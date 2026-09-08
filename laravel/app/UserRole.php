<?php

namespace App;

enum UserRole: string
{
    case Viewer = 'viewer';
    case Operator = 'operator';
    case Admin = 'admin';
}
