<?php

namespace App\Http\Resources\Libreria;

use App\Models\Libreria\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Cliente $cliente */
        $cliente = $this->resource;

        return [
            'id' => $cliente->getKey(),
            'name' => $cliente->getAttribute('nombre'),
            'documents' => (object) ['13' => $cliente->getAttribute('dui'), '36' => $cliente->getAttribute('nit')],
            'nrc' => $cliente->getAttribute('registro_fiscal'),
            'address' => $cliente->getAttribute('direccion'),
            'department' => $cliente->getAttribute('departamento'),
            'district' => $cliente->getAttribute('distrito'),
            'municipality' => $cliente->getAttribute('municipio'),
            'email' => $cliente->getAttribute('email'),
            'phone' => $cliente->getAttribute('telefono_1'),
            'phone2' => $cliente->getAttribute('telefono_2'),
            'activity' => $cliente->getAttribute('actividad_economica'),
        ];
    }
}
