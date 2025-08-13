<?php

namespace App\Models;

use Facade\FlareClient\Time\Time;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Scopes\ZoneScope;

class Awtdeliverymantimestamp extends Model
{
    use HasFactory;

    public function deliveryman()
    {
        return $this->belongsTo(Awtdeliverymantimestamp::class, 'user_id', 'id');
    }
}
