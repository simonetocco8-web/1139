<?php

namespace App\Domains\Identity\Authorization;

use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class TenantContext
{
    public function __construct(private ActiveLodge $active, private TenantAccess $access) {}

    public function resolve(string $permission = 'read'): Lodge
    {
        $user = VerifiedSession::user();
        $lodge = $this->active->current();
        abort_unless($lodge !== null, 409, 'Seleziona una loggia autorizzata.');
        abort_unless($this->access->allows($user, $lodge->id, $permission), 403, 'Operazione non autorizzata per questa loggia.');
        if (! $this->access->ordinary($user, $lodge->id, $permission)) {
            $grant = $this->access->grant($user, $lodge->id, $permission);
            Audit::record('assistance.used', $user->id, $lodge->id, metadata: ['grant_id' => $grant?->id, 'permission' => $permission]);
        }

        return $lodge;
    }

    public function assertTenant(int $mountedLodgeId, string $permission): Lodge
    {
        $lodge = $this->resolve($permission);
        abort_unless($lodge->id === $mountedLodgeId, 409, 'La loggia attiva è cambiata. Ricarica la pagina.');

        return $lodge;
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scope(Builder $query, string $permission = 'read', string $ownerColumn = 'user_id'): Builder
    {
        $lodge = $this->resolve($permission);
        $table = $query->getModel()->getTable();
        if (! Schema::hasColumn($table, 'lodge_id')) {
            throw new \LogicException('Il modello deve dichiarare il tenant.');
        }
        $query->where($table.'.lodge_id', $lodge->id);
        $user = VerifiedSession::user();
        if ($permission === 'read' && ! $this->access->fullRead($user, $lodge->id)) {
            if (! Schema::hasColumn($table, $ownerColumn)) {
                throw new \LogicException('La lettura personale richiede il proprietario.');
            }
            $query->where($table.'.'.$ownerColumn, $user->id);
        }

        return $query;
    }

    public function record(Model $record, string $permission, string $ownerColumn = 'user_id'): void
    {
        $lodge = $this->resolve($permission);
        abort_unless((int) $record->getAttribute('lodge_id') === $lodge->id, 404);
        $user = VerifiedSession::user();
        if ($permission === 'read' && ! $this->access->fullRead($user, $lodge->id)) {
            abort_unless((int) $record->getAttribute($ownerColumn) === $user->id, 403);
        }
    }
}
