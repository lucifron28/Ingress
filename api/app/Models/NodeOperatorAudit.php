<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class NodeOperatorAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'created_at'];

    protected static function booted(): void
    {
        static::updating(static function (self $audit): void {
            throw new LogicException('Node operator audit records cannot be changed.');
        });

        static::deleting(static function (self $audit): void {
            throw new LogicException('Node operator audit records cannot be deleted.');
        });
    }
}
