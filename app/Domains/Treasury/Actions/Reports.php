<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Reports
{
    public const KINDS = ['opening', 'income', 'expense', 'transfer', 'adjustment', 'capitation', 'levy', 'reversal'];

    /** @param array<string,mixed> $input
     * @return Builder<Operation> */
    public function query(int $lodgeId, array $input): Builder
    {
        $access = app(Access::class);
        $access->guard($lodgeId);
        $v = Validator::make($input, ['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'kind' => ['nullable', Rule::in(self::KINDS)], 'category_id' => ['nullable', 'integer'], 'account_id' => ['nullable', 'integer']])->validate();
        $q = Operation::query()->where('lodge_id', $lodgeId);
        if (! empty($v['from'])) {
            $q->where('booked_on', '>=', $v['from']);
        }if (! empty($v['to'])) {
            $q->where('booked_on', '<=', $v['to']);
        }if (! empty($v['kind'])) {
            $q->where('kind', $v['kind']);
        }
        if (! empty($v['category_id'])) {
            Category::query()->where('lodge_id', $lodgeId)->whereKey($v['category_id'])->firstOrFail();
            $q->where('category_id', $v['category_id']);
        }
        if (! empty($v['account_id'])) {
            $access->account($lodgeId, (int) $v['account_id']);
            $q->whereHas('lines', fn ($q) => $q->where('account_id', $v['account_id']));
        }

        return $q;
    }

    public static function cell(string $value): string
    {
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
