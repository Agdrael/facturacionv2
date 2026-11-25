-- Installed explicitly on activation, on the database shared by V1 and V2.
-- Restricted search_path and qualified tables allow V1's writer to use the
-- journal without granting that account general access to the libreria schema.
CREATE OR REPLACE FUNCTION libreria.legacy_cash_movement() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE
    box_code text;
    box_row public.cajas%ROWTYPE;
    turn_row public.inicio_cierre_caja%ROWTYPE;
    movement_id bigint;
    movement_kind text;
BEGIN
    IF current_setting('libreria.cash_source', true) = 'v2' THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;
    IF TG_TABLE_NAME = 'ventas' THEN
        movement_kind := 'venta';
        IF TG_OP = 'DELETE' THEN
            box_code := substring(OLD.numero_control FROM '^DTE-[0-9]{2}-[A-Z0-9]{4}(P00[123])-');
            movement_id := OLD.id_venta;
        ELSE
            box_code := substring(NEW.numero_control FROM '^DTE-[0-9]{2}-[A-Z0-9]{4}(P00[123])-');
            movement_id := NEW.id_venta;
        END IF;
    ELSE
        movement_kind := 'recibo';
        IF TG_OP = 'DELETE' THEN box_code := OLD.numero_caja; movement_id := OLD.id;
        ELSE box_code := NEW.numero_caja; movement_id := NEW.id; END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM libreria.legacy_cash_registers WHERE register = box_code AND active) THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;
    BEGIN
        SELECT * INTO STRICT box_row FROM public.cajas WHERE codigo_caja = box_code FOR UPDATE;
    EXCEPTION WHEN NO_DATA_FOUND OR TOO_MANY_ROWS THEN
        RAISE EXCEPTION 'Integración V1: caja % inexistente o duplicada', box_code;
    END;
    IF TG_OP <> 'INSERT' THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;
    -- Reversal documents do not represent new money and can be recorded after closing.
    IF NEW.anulado IS TRUE THEN RETURN NEW; END IF;
    IF TG_TABLE_NAME = 'ventas' THEN
        IF NEW.operacion = '05' THEN RETURN NEW; END IF;
    END IF;
    IF NOT box_row.estado_caja THEN RAISE EXCEPTION 'La caja % de V1 está cerrada', box_code; END IF;
    BEGIN
        SELECT * INTO STRICT turn_row FROM public.inicio_cierre_caja
        WHERE id_caja = box_row.id AND hora_cierre IS NULL FOR UPDATE;
    EXCEPTION WHEN NO_DATA_FOUND OR TOO_MANY_ROWS THEN
        RAISE EXCEPTION 'Integración V1: la caja % no tiene un único turno abierto', box_code;
    END;
    INSERT INTO libreria.legacy_cash_sessions(legacy_id, register, opened_at)
    VALUES(turn_row.id, box_code, (turn_row.fecha + turn_row.hora_inicio) AT TIME ZONE 'America/El_Salvador')
    ON CONFLICT (legacy_id) DO NOTHING;
    INSERT INTO libreria.legacy_cash_movements(kind, legacy_id, register, legacy_session_id, recorded_at)
    VALUES(movement_kind, movement_id, box_code, turn_row.id, clock_timestamp());
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION libreria.legacy_cash_turn() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE box_row public.cajas%ROWTYPE;
BEGIN
    SELECT * INTO box_row FROM public.cajas WHERE id = NEW.id_caja FOR UPDATE;
    IF NOT EXISTS (SELECT 1 FROM libreria.legacy_cash_registers WHERE register = box_row.codigo_caja AND active) THEN RETURN NEW; END IF;
    IF TG_OP = 'INSERT' AND NOT box_row.estado_caja THEN
        RAISE EXCEPTION 'La caja % de V1 fue cerrada durante la apertura; vuelve a abrirla', box_row.codigo_caja;
    END IF;
    IF NEW.hora_cierre IS NULL AND (SELECT count(*) FROM public.inicio_cierre_caja WHERE id_caja = NEW.id_caja AND hora_cierre IS NULL) <> 1 THEN
        RAISE EXCEPTION 'Integración V1: varios turnos abiertos para %', box_row.codigo_caja;
    END IF;
    INSERT INTO libreria.legacy_cash_sessions(legacy_id, register, opened_at, closed_at)
    VALUES(NEW.id, box_row.codigo_caja, (NEW.fecha + NEW.hora_inicio) AT TIME ZONE 'America/El_Salvador',
        CASE WHEN NEW.hora_cierre IS NULL THEN NULL
            ELSE COALESCE(NULLIF(current_setting('libreria.cash_closed_at', true), '')::timestamptz, clock_timestamp()) END)
    ON CONFLICT (legacy_id) DO UPDATE SET closed_at = EXCLUDED.closed_at;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS libreria_cash_ventas ON public.ventas;
CREATE TRIGGER libreria_cash_ventas BEFORE INSERT OR UPDATE OR DELETE ON public.ventas
FOR EACH ROW EXECUTE FUNCTION libreria.legacy_cash_movement();
DROP TRIGGER IF EXISTS libreria_cash_recibos ON public.recibos;
CREATE TRIGGER libreria_cash_recibos BEFORE INSERT OR UPDATE OR DELETE ON public.recibos
FOR EACH ROW EXECUTE FUNCTION libreria.legacy_cash_movement();
DROP TRIGGER IF EXISTS libreria_cash_turn ON public.inicio_cierre_caja;
CREATE TRIGGER libreria_cash_turn AFTER INSERT OR UPDATE ON public.inicio_cierre_caja
FOR EACH ROW EXECUTE FUNCTION libreria.legacy_cash_turn();
