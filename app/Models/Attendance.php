<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = ['user_id', 'type', 'latitude', 'longitude', 'address', 'photo_path', 'date'];

    public function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * Send timestamps as Lagos wall-clock time with NO timezone suffix,
     * so the browser shows exactly what is stored (no conversion).
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}