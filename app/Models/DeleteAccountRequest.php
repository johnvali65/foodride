<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeleteAccountRequest extends Model
{
    protected $fillable = [
        'name',
        'email',
        'mobile_number',
        'message',
        'type'
    ];
}
