<?php

namespace App\Http\Resources\Libreria;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalePackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => 'package-'.$this->resource->id_paquete,
            'packageId' => (int) $this->resource->id_paquete,
            'code' => (string) $this->resource->codigo_paquete,
            'name' => (string) $this->resource->nombre_paquete,
            'category' => 'paquetes',
            'componentsCount' => (int) $this->resource->components_count,
            'canSelect' => (int) $this->resource->components_count > 0,
            'detailUrl' => route('libreria.paquetes.show', ['package' => $this->resource->id_paquete], false),
        ];
    }
}
