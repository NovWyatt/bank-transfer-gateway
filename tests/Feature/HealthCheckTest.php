<?php

use Illuminate\Support\Facades\DB;

it('trả về 200 ở /up', function () {
    $this->get('/up')->assertOk();
});

it('kết nối được database', function () {
    expect(DB::selectOne('select 1 as ok')->ok)->toBe(1);
});

it('giữ phiên database ở UTC', function () {
    expect(DB::selectOne('select @@session.time_zone as tz')->tz)->toBe('+00:00');
});
