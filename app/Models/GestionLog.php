<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GestionLog extends Model
{
    protected $fillable = [
        'batch_id', 'cedula', 'accion', 'resultado', 'comentario', 'status', 'error_message',
    ];
}
