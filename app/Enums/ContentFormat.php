<?php

namespace App\Enums;

enum ContentFormat: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Video = 'video';
    case Poll = 'poll';
    case Album = 'album';
    case Link = 'link';
    case Document = 'document';
    case Other = 'other';
}
