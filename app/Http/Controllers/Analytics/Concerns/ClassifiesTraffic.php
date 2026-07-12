<?php

namespace App\Http\Controllers\Analytics\Concerns;

/**
 * Shared ClickHouse SQL fragments that turn (utm_source, utm_medium, referrer)
 * into friendly source / medium buckets. Kept identical to the logic in
 * CampaignsController so attribution is consistent across features (LTV,
 * channel mix, etc.).
 */
trait ClassifiesTraffic
{
    /**
     * True when a UTM value is real — not blank, not corrupted (contains the
     * UTF-8 replacement char, U+FFFD — happens when an ad network's redirect
     * chain mangles the query string), and not an ad platform's dynamic-tag
     * placeholder that failed to interpolate (TikTok's "__CAMPAIGN_NAME__",
     * Meta's "{{campaign.name}}"). Garbage/placeholder values fall through to
     * referrer-based classification instead of polluting the campaigns list.
     */
    protected function isCleanUtmSql(string $col): string
    {
        return "{$col} != '' AND position({$col}, unhex('EFBFBD')) = 0"
            . " AND NOT startsWith({$col}, '__') AND NOT startsWith({$col}, '{')";
    }

    /** utm_campaign for display — '(not set)' for blank/corrupted/unexploded-macro values. */
    protected function cleanCampaignSql(): string
    {
        $clean = $this->isCleanUtmSql('utm_campaign');
        return "if({$clean}, utm_campaign, '(not set)')";
    }

    protected function sourceClassificationSql(): string
    {
        $cleanSource = $this->isCleanUtmSql('utm_source');
        return <<<SQL
            multiIf(
                {$cleanSource},
                    multiIf(
                        lower(utm_source) IN ('ig', 'instagram'),                                          'Instagram',
                        lower(utm_source) IN ('fb', 'facebook'),                                            'Facebook',
                        lower(utm_source) IN ('tiktok', 'tt'),                                              'TikTok',
                        lower(utm_source) IN ('google', 'adwords', 'gads', 'googleads', 'google_ads'),      'Google',
                        lower(utm_source) IN ('snap', 'snapchat'),                                          'Snapchat',
                        lower(utm_source) IN ('twitter', 'x'),                                              'X (Twitter)',
                        lower(utm_source) IN ('yt', 'youtube'),                                             'YouTube',
                        lower(utm_source) IN ('wa', 'whatsapp'),                                            'WhatsApp',
                        lower(utm_source) IN ('tg', 'telegram'),                                            'Telegram',
                        lower(utm_source) IN ('li', 'linkedin'),                                            'LinkedIn',
                        utm_source
                    ),
                referrer    = '',                                                                                       '(direct)',
                domain(referrer) IN ('mail.google.com', 'gmail.com', 'inbox.google.com'),                              'Gmail',
                domain(referrer) IN ('outlook.live.com', 'outlook.office.com', 'outlook.com', 'mail.live.com'),        'Outlook',
                domain(referrer) IN ('mail.yahoo.com'),                                                                'Yahoo Mail',
                domain(referrer) IN ('mail.proton.me', 'mail.protonmail.com'),                                         'ProtonMail',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'google.'),                                       'Google',
                cutToFirstSignificantSubdomain(referrer) IN ('bing.com'),                                              'Bing',
                cutToFirstSignificantSubdomain(referrer) IN ('duckduckgo.com'),                                        'DuckDuckGo',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'yahoo.'),                                        'Yahoo',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'yandex.'),                                       'Yandex',
                cutToFirstSignificantSubdomain(referrer) IN ('baidu.com'),                                             'Baidu',
                cutToFirstSignificantSubdomain(referrer) IN ('ecosia.org', 'qwant.com', 'startpage.com', 'brave.com'), 'Other search',
                cutToFirstSignificantSubdomain(referrer) IN ('facebook.com', 'fb.com', 'fb.me'),                       'Facebook',
                cutToFirstSignificantSubdomain(referrer) IN ('messenger.com'),                                         'Messenger',
                cutToFirstSignificantSubdomain(referrer) IN ('instagram.com', 'instagr.am'),                           'Instagram',
                cutToFirstSignificantSubdomain(referrer) IN ('twitter.com', 'x.com', 't.co'),                          'X (Twitter)',
                cutToFirstSignificantSubdomain(referrer) IN ('threads.net'),                                           'Threads',
                cutToFirstSignificantSubdomain(referrer) IN ('linkedin.com', 'lnkd.in'),                               'LinkedIn',
                cutToFirstSignificantSubdomain(referrer) IN ('youtube.com', 'youtu.be'),                               'YouTube',
                cutToFirstSignificantSubdomain(referrer) IN ('tiktok.com'),                                            'TikTok',
                cutToFirstSignificantSubdomain(referrer) IN ('reddit.com'),                                            'Reddit',
                cutToFirstSignificantSubdomain(referrer) IN ('pinterest.com', 'pin.it'),                               'Pinterest',
                cutToFirstSignificantSubdomain(referrer) IN ('snapchat.com'),                                          'Snapchat',
                cutToFirstSignificantSubdomain(referrer) IN ('whatsapp.com', 'wa.me'),                                 'WhatsApp',
                cutToFirstSignificantSubdomain(referrer) IN ('t.me', 'telegram.org'),                                  'Telegram',
                cutToFirstSignificantSubdomain(referrer) IN ('discord.com', 'discord.gg'),                             'Discord',
                cutToFirstSignificantSubdomain(referrer) IN ('vk.com'),                                                'VKontakte',
                cutToFirstSignificantSubdomain(referrer) IN ('github.com'),                                            'GitHub',
                cutToFirstSignificantSubdomain(referrer) IN ('medium.com'),                                            'Medium',
                cutToFirstSignificantSubdomain(referrer) IN ('quora.com'),                                             'Quora',
                cutToFirstSignificantSubdomain(referrer) IN ('stackoverflow.com'),                                     'Stack Overflow',
                cutToFirstSignificantSubdomain(referrer) IN ('producthunt.com'),                                       'Product Hunt',
                domain(referrer) = 'news.ycombinator.com',                                                             'Hacker News',
                cutToFirstSignificantSubdomain(referrer) IN ('substack.com'),                                          'Substack',
                cutToFirstSignificantSubdomain(referrer) IN ('chatgpt.com', 'openai.com'),                             'ChatGPT',
                cutToFirstSignificantSubdomain(referrer) IN ('claude.ai', 'anthropic.com'),                            'Claude',
                cutToFirstSignificantSubdomain(referrer) IN ('perplexity.ai'),                                         'Perplexity',
                cutToFirstSignificantSubdomain(referrer) IN ('gemini.google.com'),                                     'Gemini',
                if(cutToFirstSignificantSubdomain(referrer) = '', '(direct)', cutToFirstSignificantSubdomain(referrer))
            )
        SQL;
    }

    protected function mediumClassificationSql(): string
    {
        $cleanMedium = $this->isCleanUtmSql('utm_medium');
        $cleanSource = $this->isCleanUtmSql('utm_source');
        return <<<SQL
            multiIf(
                {$cleanMedium},                                                                                          utm_medium,
                {$cleanSource},                                                                                          'campaign',
                referrer    = '',                                                                                       '(none)',
                domain(referrer) IN (
                    'mail.google.com', 'gmail.com', 'inbox.google.com',
                    'outlook.live.com', 'outlook.office.com', 'outlook.com', 'mail.live.com',
                    'mail.yahoo.com', 'mail.proton.me', 'mail.protonmail.com'
                ),                                                                                                      'email',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'google.')
                    OR cutToFirstSignificantSubdomain(referrer) IN ('bing.com', 'duckduckgo.com', 'baidu.com', 'ecosia.org', 'qwant.com', 'startpage.com', 'brave.com')
                    OR startsWith(cutToFirstSignificantSubdomain(referrer), 'yahoo.')
                    OR startsWith(cutToFirstSignificantSubdomain(referrer), 'yandex.'),                                'organic',
                cutToFirstSignificantSubdomain(referrer) IN (
                    'facebook.com','fb.com','fb.me','messenger.com',
                    'instagram.com','instagr.am','threads.net',
                    'twitter.com','x.com','t.co',
                    'linkedin.com','lnkd.in',
                    'youtube.com','youtu.be',
                    'tiktok.com','reddit.com',
                    'pinterest.com','pin.it',
                    'snapchat.com','whatsapp.com','wa.me',
                    't.me','telegram.org',
                    'discord.com','discord.gg','vk.com'
                ),                                                                                                      'social',
                cutToFirstSignificantSubdomain(referrer) IN (
                    'chatgpt.com','openai.com','claude.ai','anthropic.com','perplexity.ai','gemini.google.com'
                ),                                                                                                      'ai',
                'referral'
            )
        SQL;
    }
}
