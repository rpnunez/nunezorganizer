<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Exceptions\CrossTenantWrite;
use App\Domain\Accounts\Exceptions\MissingTenantContext;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant scoping for Eloquent models that carry an `account_id` column.
 *
 * - Reads are filtered to the current tenant. With no tenant context, queries throw
 *   MissingTenantContext (fail closed) unless run inside TenantContext::withoutTenancy().
 * - Creates are stamped with the current tenant's account_id; an explicit different
 *   account_id is rejected. Without a context, an explicit account_id is required
 *   (system code such as account provisioning).
 * - account_id can never change after creation.
 * - account_id must not be mass assignable: keep it out of $fillable.
 *
 * @mixin Model
 */
trait BelongsToAccount
{
    public const string TENANT_SCOPE = 'tenant';

    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(self::TENANT_SCOPE, function (Builder $builder): void {
            $context = app(TenantContext::class);

            if ($context->isBypassed()) {
                return;
            }

            if (! $context->has()) {
                throw new MissingTenantContext;
            }

            $builder->where($builder->qualifyColumn('account_id'), $context->accountId());
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $explicit = $model->getAttribute('account_id');

            if ($context->has() && ! $context->isBypassed()) {
                if ($explicit !== null && $explicit !== $context->accountId()) {
                    throw new CrossTenantWrite;
                }

                $model->setAttribute('account_id', $context->accountId());

                return;
            }

            if ($explicit === null) {
                throw new MissingTenantContext('Cannot create tenant-owned data without a tenant context or explicit account.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('account_id')) {
                throw new CrossTenantWrite('account_id cannot be changed after creation.');
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
