<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class NotificationDeliveryAttempt extends Model { protected $fillable=['event_key','recipient_id','channel','attempts']; protected $casts=['attempts'=>'integer']; }
