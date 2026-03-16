<?php

namespace App;

enum SocialAuthProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
}
