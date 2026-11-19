<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'admin_level' => $this->admin_level,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'referral_code' => $this->client->referral_code,
                'preferred_contact' => $this->client->preferred_contact,
            ] : null),
            'cleaner' => $this->whenLoaded('cleaner', fn () => $this->cleaner ? [
                'id' => $this->cleaner->id,
                'status' => $this->cleaner->status,
                'weekly_off_day' => $this->cleaner->weekly_off_day,
            ] : null),
        ];
    }
}