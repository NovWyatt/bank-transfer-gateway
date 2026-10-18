<?php

use Illuminate\Support\Facades\DB;

it('trả về 200 ở /up', function () {
    $this->get('/up')->assertOk();
});

it('kết nối được database', function () {
    expect(DB::selectOne('select 1 as ok')->ok)->toBe(1);
});
