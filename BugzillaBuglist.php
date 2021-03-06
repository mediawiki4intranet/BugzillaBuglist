<?php

/**
 * This extension provides buglist and bug attachment list Bugzilla integration
 * (bug attachment lists work only with Bugzilla4Intranet).
 *
 * To insert bug list, use a tag: <buglist user="" query="" />
 * or a parser function: {{#buglist:user|query}}
 *
 * To insert attachment list, use: <bugattachments user="" bugid="{bugid}" />
 * or a parser function: {#bugattachments:user|bugid}}
 *
 * Lists are fetched from under one of configured user(s) and cached for some time
 * in the wiki object cache.
 *
 * Configuration:
 *
 * $egBugzillaBuglistUsers = array(
 *   'alias' => array('login' => BUGZILLA LOGIN, 'password' => BUGZILLA PASSWORD),
 * );
 * $egBugzillaBuglistCacheTime = 10; // seconds
 * $egBugzillaBuglistUrl = 'http://bugzilla.office.local/';
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @copyright (c) 2010-2013 Vitaliy Filippov
 * @licence GNU General Public Licence 2.0 or later
 */

if (!defined('MEDIAWIKI'))
{
    die("This file is an extension to the MediaWiki software and cannot be used standalone");
}

$wgExtensionMessagesFiles['BugzillaBuglist'] = dirname(__FILE__).'/BugzillaBuglist.i18n.php';
$wgExtensionCredits['parserhook'][] = array(
    'name'    => 'Bugzilla Buglist',
    'author'  => 'Vitaliy Filippov',
    'version' => '0.9a',
);
$wgHooks['ParserFirstCallInit'][] = 'efBugzillaBuglist';
$wgHooks['LanguageGetMagic'][] = 'efBugzillaBuglistLanguageGetMagic';

/* Default configuration */
$egBugzillaBuglistUsers = array();
$egBugzillaBuglistCacheTime = 10;
$egBugzillaBuglistUrl = '';

/* Add magic word for parser function */
function efBugzillaBuglistLanguageGetMagic(&$magicWords, $langCode)
{
    $magicWords['buglist'] = array(0, 'buglist');
    $magicWords['bugattachments'] = array(0, 'bugattachments');
    return true;
}

/* Registers parser hooks */
function efBugzillaBuglist($parser)
{
    $parser->setHook('buglist', 'efRenderBugzillaBuglist');
    $parser->setFunctionHook('buglist', 'efRenderBugzillaBuglistPF');

    $parser->setHook('bugattachments', 'efRenderBugzillaBugattachments');
    $parser->setFunctionHook('bugattachments', 'efRenderBugzillaBugattachmentsPF');
    return true;
}

/* Parser function, returns wiki-text */
function efRenderBugzillaBuglistPF($parser, $user, $query)
{
    $args = array('user' => $user, 'query' => $query);
    return efRenderBugzillaBuglist('', $args, $parser);
}

/* Show attachment of the bug */
function efRenderBugzillaBugattachmentsPF($parser, $user, $query)
{
    $args = array('user' => $user, 'query' => $query, 'attachments' => true);
    return efRenderBugzillaBuglist('', $args, $parser);
}

function efRenderBugzillaBugattachments($content, $args, $parser)
{
    return efRenderBugzillaBuglist($content, $args + array(
        'query' => isset($args['bugid']) ? $args['bugid'] : NULL,
        'attachments' => true
    ), $parser);
}

/* Tag function, returns wiki-text */
function efRenderBugzillaBuglist($content, $args, $parser)
{
    global $egBugzillaBuglistUsers, $egBugzillaBuglistUrl, $egBugzillaBuglistCacheTime;
    $parser->disableCache();
    $username = $args['user'];
    $query = isset($args['query']) ? $args['query'] : false;
    $attachments = isset($args['attachments']) ? $args['attachments'] : false;
    if (!$egBugzillaBuglistUrl)
    {
        return wfMsgNoTrans('buglist-no-url');
    }
    if (!$egBugzillaBuglistUsers[$username] ||
        !$egBugzillaBuglistUsers[$username]['login'] ||
        !$egBugzillaBuglistUsers[$username]['password'])
    {
        return wfMsgNoTrans('buglist-invalid-username', $username, $query);
    }
    $url = $egBugzillaBuglistUrl;
    if (substr($url, -1) != '/')
    {
        $url .= '/';
    }
    $cache = wfGetCache(CACHE_ANYTHING);
    $contentkey = wfMemcKey('bugzilla_buglist', $username, $query);
    if (!($html = $cache->get($contentkey)))
    {
        $authkey = wfMemcKey('bugzilla_logincookie', $username);
        if (!preg_match('/\d/', $cookie = $cache->get($authkey)))
        {
            /* Log in to Bugzilla */
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url . 'index.cgi');
            curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array(
                'GoAheadAndLogIn'   => 1,
                'Bugzilla_login'    => $egBugzillaBuglistUsers[$username]['login'],
                'Bugzilla_password' => $egBugzillaBuglistUsers[$username]['password'],
            )));
            $head = curl_exec($curl);
            if (($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) != 200)
            {
                $cache->delete($authkey);
                return wfMsgNoTrans('buglist-http-error', $code);
            }
            else
            {
                $head = substr($head, 0, strpos($head, "\n\n"));
                preg_match('#Set-Cookie:\s*Bugzilla_login=(\d+|X)#is', $head, $m);
                $l = $m[1];
                preg_match('#Set-Cookie:\s*Bugzilla_logincookie=([A-Za-z0-9]+)#is', $head, $m);
                $c = $m[1];
                if (!$l || !$c || $l == 'X' || $c == 'X')
                {
                    $cache->delete($authkey);
                    return wfMsgNoTrans('buglist-login-incorrect', $egBugzillaBuglistUsers[$username]['login']);
                }
                $cookie = "Cookie: Bugzilla_login=$l; Bugzilla_logincookie=$c";
                /* Remember the cookie */
                $cache->set($authkey, $cookie, 86400);
            }
        }
        /* Fetch page content */
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_URL, $url . ($attachments
            ? 'attachment.cgi?bugid='.urlencode($query).'&action=viewall&format=simple'
            : 'buglist.cgi?format=simple&cmdtype=runnamed&namedcmd='.urlencode($query))
        );
        curl_setopt($curl, CURLOPT_HTTPHEADER, array($cookie));
        $html = curl_exec($curl);
        if (($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) != 200)
        {
            return wfMsgNoTrans('buglist-http-error', $code);
        }
        list($header, $html) = explode("\n\n", $html, 2);
        if (preg_match('#Set-Cookie:\s*Bugzilla_login=X#is', $header))
        {
            $cache->delete($authkey);
            return efRenderBugzillaBuglist($content, $args, $parser);
        }
        /* Clean HTML code */
        // extract body
        preg_match('#<body[^<>]*>(.*)</body#is', $html, $m);
        $html = $m[1];
        // remove <thead> and <tbody> for js-sortable tables to work
        $html = preg_replace('#</?t(head|body)[^<>]*>#is', '', $html);
        // add unsortable class for time summary line
        $html = str_replace('bz_time_summary_line', 'bz_time_summary_line unsortable', $html);
        // remove buglist links (resort etc)
        $html = preg_replace('#<a[^<>]*href=["\'][^"\']*buglist.cgi[^"\']*["\'][^<>]*>(.*?)</a\s*>#is', '\1', $html);
        // make other links absolute
        $html = preg_replace('#<a\s+([^<>]*href=["\'])(?!https?://)#is', '<a target="_blank" \1'.$url, $html);
        $cache->set($contentkey, $html, $egBugzillaBuglistCacheTime);
    }
    /* Protect the HTML code from being escaped */
    $marker = $parser->mUniqPrefix."-buglist-" . sprintf('%08X', $parser->mMarkerIndex++) . Parser::MARKER_SUFFIX;
    if (method_exists($parser->mStripState, 'addNoWiki'))
    {
        $parser->mStripState->addNoWiki($marker, $html);
    }
    else
    {
        $parser->mStripState->nowiki->setPair($marker, $html);
    }
    $parser->mOutput->addHeadItem("<link rel='stylesheet' type='text/css' href='$url/skins/standard/buglist.css' />");
    return $marker;
}
