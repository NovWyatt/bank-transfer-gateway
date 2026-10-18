<?php

/*
| Thông báo kiểm tra dữ liệu bằng tiếng Việt cho các luật app đang dùng.
| Luật nào chưa có ở đây thì Laravel lấy bản tiếng Anh (APP_FALLBACK_LOCALE=en).
*/

return [
    'array' => ':Attribute phải là một danh sách.',
    'boolean' => ':Attribute chỉ nhận đúng hoặc sai.',
    'date' => ':Attribute không phải ngày hợp lệ.',
    'email' => ':Attribute phải là địa chỉ email hợp lệ.',
    'exists' => ':Attribute đã chọn không tồn tại.',
    'in' => ':Attribute đã chọn không hợp lệ.',
    'integer' => ':Attribute phải là số nguyên.',
    'max' => [
        'array' => ':Attribute có tối đa :max mục.',
        'numeric' => ':Attribute không được lớn hơn :max.',
        'string' => ':Attribute dài tối đa :max ký tự.',
    ],
    'min' => [
        'array' => ':Attribute cần ít nhất :min mục.',
        'numeric' => ':Attribute phải từ :min trở lên.',
        'string' => ':Attribute cần ít nhất :min ký tự.',
    ],
    'numeric' => ':Attribute phải là số.',
    'regex' => ':Attribute sai định dạng.',
    'required' => 'Cần điền :attribute.',
    'required_without' => 'Cần điền :attribute khi không có :values.',
    'string' => ':Attribute phải là chuỗi ký tự.',
    'unique' => ':Attribute đã được dùng.',
    'url' => ':Attribute phải là URL hợp lệ.',
    'uuid' => ':Attribute phải là UUID hợp lệ.',

    'custom' => [],

    'attributes' => [],
];
