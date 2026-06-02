<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailSuppression extends Model
{
    protected $table = 'v2_mail_suppressions';

    protected $fillable = [
        'email',
        'reason',
        'source',
        'diagnostic_code',
        'raw_excerpt',
    ];
}
