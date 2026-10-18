<?php

namespace App\Enums;

enum OrderSource: string
{
    case Dashboard = 'dashboard';
    case Api = 'api';
    case Demo = 'demo';
}
