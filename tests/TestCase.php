<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Test fixtures model a legitimate local transfer; deliberately inconsistent
    // legacy memberships are created separately without calling this helper.
    protected function useLocalLodge(User $user, int $lodgeId): void
    {
        if ($user->is_platform_admin) {
            return;
        }
        DB::table('lodge_user')->where('user_id', $user->id)->where('lodge_id', '!=', $lodgeId)->update(['is_active' => false]);
        DB::table('lodge_user')->where('user_id', $user->id)->where('lodge_id', $lodgeId)->update(['is_active' => true]);
    }
}
