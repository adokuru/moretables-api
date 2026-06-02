<?php

namespace App;

enum MoretableLineupStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
