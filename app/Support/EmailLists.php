<?php

namespace App\Support;

/**
 * Small curated lists used by the email validator. They are deliberately conservative and NOT exhaustive:
 * disposable-mail domains appear daily, so treat "risky: disposable" as a helpful signal, not a guarantee.
 */
final class EmailLists
{
    /** @var list<string> */
    public const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamail.biz', 'sharklasers.com',
        'grr.la', 'guerrillamailblock.com', '10minutemail.com', '10minutemail.net', '20minutemail.com', 'tempmail.com',
        'temp-mail.org', 'temp-mail.io', 'tempmail.net', 'tempmailo.com', 'tempail.com', 'throwawaymail.com', 'trashmail.com',
        'trashmail.net', 'trashmail.de', 'yopmail.com', 'yopmail.net', 'yopmail.fr', 'getnada.com', 'nada.email', 'maildrop.cc',
        'dispostable.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'spamgourmet.com',
        'mytemp.email', 'moakt.com', 'burnermail.io', 'discard.email', 'discardmail.com', 'spambox.us', 'spam4.me', 'mailcatch.com',
        'inboxbear.com', 'tmail.ws', 'tmpmail.org', 'tmpmail.net', 'emailfake.com', 'fakemail.net', 'fake-mail.net', 'harakirimail.com',
        'mailforspam.com', 'jetable.org', '33mail.com', 'anonaddy.me', 'owlymail.com', 'minuteinbox.com', 'luxusmail.org',
        'e4ward.com', 'mail-temporaire.fr', 'nowmymail.com', 'tempinbox.com', 'dropmail.me', 'mailpoof.com', 'linshiyouxiang.net',
    ];

    /** Local parts that usually belong to a team mailbox, not a person: low engagement, higher complaint rate. */
    public const ROLE_ACCOUNTS = [
        'admin', 'administrator', 'info', 'contact', 'support', 'help', 'sales', 'marketing', 'billing', 'accounts', 'accounting',
        'office', 'hello', 'enquiry', 'enquiries', 'inquiry', 'hr', 'jobs', 'careers', 'webmaster', 'postmaster', 'hostmaster',
        'abuse', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'root', 'team', 'service', 'feedback',
    ];

    /** Common mistyped webmail domains => the domain people meant. */
    public const DOMAIN_TYPOS = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com',
        'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmaill.com' => 'gmail.com',
        'gnail.com' => 'gmail.com', 'gimail.com' => 'gmail.com', 'gmeil.com' => 'gmail.com', 'gmail.comm' => 'gmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahho.com' => 'yahoo.com',
        'yhoo.com' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com',
        'outllok.com' => 'outlook.com', 'rediffmal.com' => 'rediffmail.com', 'rediffmail.con' => 'rediffmail.com',
        'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com',
    ];
}
