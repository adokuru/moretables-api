<?php

namespace App;

enum UserAuthMethod: string
{
    case Password = 'password';
    case Passwordless = 'passwordless';
    case Social = 'social';
}
