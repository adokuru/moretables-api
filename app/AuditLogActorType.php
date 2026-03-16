<?php

namespace App;

enum AuditLogActorType: string
{
    case User = 'user';
    case System = 'system';
}
