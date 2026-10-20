<?php

return [

    /*
    | Nút "Giả lập khách chuyển khoản" trên trang đơn (chỉ owner) và lệnh pay:simulate.
    | Dự án demo nên bật cả trên production. Một shop thật nên tắt: nút này đánh dấu đơn
    | đã trả mà không có tiền thật (giao dịch vẫn ghi nhà cung cấp là "Giả lập").
    */
    'simulator_enabled' => (bool) env('PAYMENTS_SIMULATOR', true),

];
