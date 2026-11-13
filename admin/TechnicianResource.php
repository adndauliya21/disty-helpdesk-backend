<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* API RESOURCE: bentuk JSON teknisi { name, specialty }. Teknisi = pengguna berperan teknisi (kolom nama dan spesialisasi). */
class TechnicianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->nama,
            'specialty' => (string) $this->spesialisasi,
        ];
    }
}
