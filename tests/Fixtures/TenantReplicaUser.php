<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-side replica of a central person, shaped as stancl's Syncable tenant model is (the flagship's
 * `TenantUser`): it names its central model and a global identifier, names no tenant model, and is NOT
 * notifiable. The central model it names is configurable per test so a replica can point at a notifiable
 * model, at a model that is not notifiable, or at nothing loadable.
 */
class TenantReplicaUser extends Model
{
    use HasUuids;

    /** @var class-string */
    public static string $centralModel = TeamMemberUser::class;

    protected $table = 'test_replica_users';

    protected $guarded = [];

    public $timestamps = false;

    public function getCentralModelName(): string
    {
        return static::$centralModel;
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }
}
