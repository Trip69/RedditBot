<?php
class bot_details
{
    public static $user_name='';
    public static $password='';
    public static $client_id='';
    public static $client_secret='';

    public static function rules():array
    {
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'unmoderated',
            'action'=>'remove',
            'mod_note'=>'no upvotes',
            'reason_id'=>'12shmkmyn5v44',
            'check'=>'created_utc<'.(time()-60*60*2).'&&score<2');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'remove',
            'mod_note'=>'reported < 10',
            'reason_id'=>'',
            'check'=>'num_reports>0&&score<11');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'remove',
            'mod_note'=>'reported < 20',
            'reason_id'=>'',
            'check'=>'num_reports>1&&score<21');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'remove',
            'mod_note'=>'reported, ratio',
            'reason_id'=>'',
            'check'=>'(score/num_reports)<100');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'flair_spam',
            'flair_template_id'=>'',
            'check'=>'(spam_reports>2)&&score<300');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'flair_repost_spam',
            'flair_template_id'=>'',
            'check'=>'(spam_repost_reports>1)&&score<200');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'flair_nopulse_spam',
            'flair_template_id'=>'',
            'check'=>'(spam_nopulse_reports>1)&&score<200');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'reports',
            'action'=>'flair_clear_spam',
            'flair_template_id'=>'',
            'check'=>'flared_as_spam&&score>300');
        $rules[]=array(
            'sub'=>'YOUR_SUBREDDIT','page'=>'log',
            'action'=>'ban_temp',
            'ban_reason'=>'Spam',
            'duration'=>2,
            'ban_message'=>'2 day ban for spamming. I am a bot message the admin if you do not agree.',
            'mod_note'=>'too much spam',
            'check'=>'array_count(spamlink_count,"value>4")');
        return $rules;
    }
}
