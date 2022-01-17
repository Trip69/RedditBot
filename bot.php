<?php
require_once('inc/httpful.phar');

class reddit_api
{
    static $url = 'https://www.reddit.com';
    static $url_oath = 'https://oauth.reddit.com';
    static $about_pages = ['reports','unmoderated','log'];

    public $last_url;

    private $bot_name;
    private $username;
    private $password;
    private $client_id;
    private $client_secret;
    private $auth_type=0;

    private $modhash=null;
    private $cookie=null;
    private $access_token=null;
    
    function __construct($bot_name,$user,$pass,$client_id=null,$client_secret=null)
    {
        $this->bot_name=$bot_name;
        $this->username=$user;
        $this->password=$pass;
        $this->client_id=$client_id;
        $this->client_secret=$client_secret;
    }
    
    function login()
    {
        if ($this->client_id===null)
            $this->login_user_pass();
        else
            $this->login_oath();
    }
    
    function login_user_pass()
    {
        $this->doCommand('/api/login',array('user'=>$this->username,'passwd'=>$this->password));
        $this->auth_type=1;
    }
    
    function login_oath()
    {
        $request = \Httpful\Request::post('https://www.reddit.com/api/v1/access_token');
        $request -> addHeader('User-Agent',$this->bot_name);
        $request->basicAuth($this->client_id,$this->client_secret);
        $request->body('grant_type=password&username='.$this->username.'&password='.$this->password);
        $request ->expects('json');
        $response = $request -> send();
        $this->access_token=$response->body->access_token;
        $this->auth_type=2;
        
        //this is redundant
        /*
        $request = \Httpful\Request::get('https://oauth.reddit.com/api/v1/me');
        $request -> addHeader('User-Agent',$this->bot_name);
        $request -> addHeader('Authorization','bearer '.$this->access_token);
        $request -> expectsJson();
        $response = $request -> send();
        echo $response;
        */
    }
    
    function doCommand($path,$data=null)
    {
        $host=$this->auth_type==1?reddit_api::$url:reddit_api::$url_oath;
        $api=strpos($path,'/api/v1/')!==false?1:2;
        if (substr($path,0,5) =='/api/' || $data!==null)
            $request = \Httpful\Request::post($host.$path);
        else
            $request = \Httpful\Request::get($host.$path);
        $this->last_url=$host.$path;
        $request -> addHeader('User-Agent',$this->bot_name);
        if ($this->modhash!==null)
            $request -> addHeader('X-Modhash',$this->modhash);
        if ($this->cookie!==null)
            $request -> addHeader('Cookie','reddit_session='.$this->cookie);
        if ($this->access_token!==null)
            $request -> addHeader('Authorization','bearer '.$this->access_token);
        if ($data!==null)
        {
            if ($api==1) {
                $request->addHeader('content-type','application/json');
                $request -> body(json_encode($data));
            } else {
                $data['api_type'] = 'json';
                $request -> body(http_build_query($data,null,'&',PHP_QUERY_RFC3986));
            }
        }
        $request -> expects('json');
        $response = null;
        try
        {
            $response = $request -> send();
        }  catch (Exception $e) {
            echo 'Error '.$e;
            die();
        }
        
        if (isset($response->body->json->data->modhash))
            $this->modhash = $response->body->json->data->modhash;
        if (isset($response->body->json->data->cookie))
            $this->cookie = $response->body->json->data->cookie;
        
        //echo $this->modhash;
        if (isset($response->body->json->data))
            return $response->body->json->data;
        else
            return $response->body;
    }
}

class reddit_bot
{
    private $api;

    public $rules = array();
    
    public $removed = 0;
    public $edited = 0;
    public $checked = 0;
    public $rules_parsed = 0;
    
    function __construct($username,$password,$client_id=null,$client_secret=null)
    {
        $this->api = new reddit_api('atpeace_bot 0.1',$username,$password,$client_id,$client_secret);
    }
    
    function login()
    {
        $this->api->login();
    }
    
    function logout()
    {
        $this->api->doCommand('/logout');
    }
    
    function process_rules()
    {
        $page=null;
        $page_path='';
        $page_hash='';
        foreach ($this->rules as $rule)
        {
            //refresh page if page is different or edited (saves gets for multiple rules testing same page)
            if (in_array($rule['page'],reddit_api::$about_pages))
                $page_path='/r/'.$rule['sub'].'/about/'.$rule['page'].'.json';
            else
                $page_path='/r/'.$rule['sub'];

            if ($page_hash!==$page_path.$this->edited) {
                $page=$this->api->doCommand($page_path);
                $page_hash=$page_path.$this->edited;
            }

            // Testing
            /*
            if($rule['page']=='log') {
                //?raw_json=1&gilding_detail=1
                $ret=$this->api->doCommand('/api/v1/modactions/removal_reasons/?raw_json=1&gilding_detail=1',array(
                    'item_ids'=>array($page->data->children[3]->data->target_fullname),
                    'reason_id'=>null,
                    'mod_note'=>'bot test'
                ));
                $stop=1;
            }
            */
            /*
            if($rule['page']=='reports') {
                $ret=$this->api->doCommand('/r/' . $rule['sub'] . '/api/selectflair', array(
                    'flair_template_id' => '07dc98c4-ca26-11ea-a0c0-0ee57b03db9b',
                    'link' => $page->data->children[0]->data->name,
                    'return_rtson' => 'none'
                ), 'POST');
            }
            */

            //Init Rule
            $rule_eval = new rule($rule['check'],$rule['action']);
            //Pass posts 1st page of posts (default 25) - if the sub is busy this will need to be extended - check api to set listing number
            foreach ($page->data->children as $post)
            {
                //only count actions on log, no eval per entry.
                if($rule['page']=='log') {
                    if($post->data->mod!='AutoModerator')
                        switch($post->data->action) {
                            case 'removelink':
                            case 'spamlink':
                                isset($rule_eval->{$post->data->action.'_count'}[$post->data->target_author])?
                                    $rule_eval->{$post->data->action.'_count'}[$post->data->target_author]++:
                                    $rule_eval->{$post->data->action.'_count'}[$post->data->target_author]=1;
                                break;
                            case 'banuser':
                                //Bury reports to prevent repeat ban.
                                $rule_eval->removelink_count[$post->data->target_author]=-25;
                                $rule_eval->spamlink_count[$post->data->target_author]=-25;
                                break;
                        }
                    continue;
                }
                //test post against rule
                $fail = $rule_eval->match($post);
                if ($fail)
                    //perform action defined by rule
                    switch ($rule['action'])
                    {
                        case 'remove':
                            if(isset($post->data->banned_by) && !is_null($post->data->banned_by))
                                break;
                            $is_spam=$rule_eval->count_reports($post,[1,2])>0;
                            $this->api->doCommand('/api/remove',array(
                                'id'=>$post->data->name,
                                'spam'=>$is_spam
                            ));
                            $this->api->doCommand('/api/v1/modactions/removal_reasons/?raw_json=1&gilding_detail=1',array(
                                'item_ids'=>array($post->data->target_fullname),
                                'reason_id'=>$rule['reason_id'],
                                'mod_note'=>$rule['mod_note']
                            ));
                            /*TODO
                             * https://oauth.reddit.com/api/v1/modactions/removal_link_message/?rtj=only&raw_json=1&gilding_detail=1
                             * item_id=>array($post->data->target_fullname)
                             * message=>"Your post didn't get an upvote in 2 hours and has been removed. Rule 7. Sorry."
                             * title=>"No Upvotes in 2 hours"
                             * type=>"public"/"private"?/"message"?
                             */
                            $this->removed++;
                            echo $rule['action'].' '.$post->data->author.' '.$post->data->title.PHP_EOL;
                            break;
                        case 'flair_spam':
                            //SPAM fid:c251ea22-ca14-11ea-9e84-0ed2938c6f25
                        case 'flair_repost_spam':
                            //07dc98c4-ca26-11ea-a0c0-0ee57b03db9b
                        case 'flair_nopulse_spam':
                            //77f66740-d06e-11ea-badd-0ecd8d2ebc75
                            $this->api->doCommand('/r/'.$rule['sub'].'/api/selectflair',array(
                                'flair_template_id'=>$rule['flair_template_id'],
                                'link'=>$post->data->name,
                                'return_rtson'=>'none'
                            ));
                            $this->edited++;
                            echo $rule['action'].'  '.$post->data->title.PHP_EOL;
                            break;
                        case 'flair_clear_spam':
                            $this->api->doCommand('/api/selectflair',array(
                                'flair_template_id'=>'',
                                'text'=>'',
                                'link'=>$post->data->name,
                                'return_rtson'=>'none'
                            ));
                            $this->edited++;
                            echo $rule['action'].'  '.$post->data->title.' flared cleared'.PHP_EOL;
                            break;
                        default:
                            echo $rule['action'].' does not match any valid actions'.PHP_EOL;
                    }
                $this->checked++;
            }
            if($rule['page']=='log') {
                $fail = $rule_eval->do_eval();
                if($fail!==false)
                    switch ($rule['action']) {
                        case 'temp_ban':
                            foreach ($fail as $username) {
                                //$break=1;
                                $this->api->doCommand('/r/'.$rule['sub'].'/api/friend',array(
                                    'ban_reason'=>$rule['ban_reason'],
                                    'ban_message'=>$rule['ban_message'],
                                    'duration'=>$rule['duration'],
                                    'name'=>$username,
                                    'note'=>$rule['mod_note'],
                                    'type'=>'banned'
                                ));
                                echo $rule['action'].'  '.$username.PHP_EOL;
                                break;
                            }
                    }
                $this->checked++;
            }
            $this->rules_parsed++;
        }
    }
}

class rule
{
    private $rule_string = '';
    private $eval = '';
    private $action = '';
    public $removelink_count=array();
    public $spamlink_count=array();

    function __construct($rule,$action)
    {
        $this->action = $action;
        $this->rule_string=$rule;
        $this->eval='return '.$rule.';';
        //replace readable string with variables
        $this->eval=str_replace('num_reports','$post->data->num_reports',$this->eval);
        $this->eval=str_replace('created_utc','$post->data->created_utc',$this->eval);
        $this->eval=str_replace('score','$post->data->score',$this->eval);
        $this->eval=str_replace('spamlink_count','$this->spamlink_count',$this->eval);
        $this->eval=str_replace('removelink_count','$this->removelink_count',$this->eval);
        $this->eval=str_replace('array_count','$this->array_count',$this->eval);
        $this->eval=str_replace('spam_reports','$this->count_reports($post,[1,2])',$this->eval);
        $this->eval=str_replace('spam_nopulse_reports','$this->count_reports($post,1)',$this->eval);
        $this->eval=str_replace('spam_repost_reports','$this->count_reports($post,2)',$this->eval);
        $this->eval=str_replace('flared_as_spam','$this->flare_has_word($post,"spam")',$this->eval);
    }

    function array_count($arr,$eval){
        $eval=str_replace('value','$value',$eval);
        $eval='return '.$eval.';';
        $ret=array();
        foreach($arr as $key=>$value)
            if(eval($eval))
                $ret[]=$key;
        return count($ret)>0?$ret:false;
    }

    function count_reports($post,$id){
        $count=0;
        for($a=0;$a<count($post->data->user_reports);$a++)
            if(is_array($id)) {
                if(array_search($post->data->user_reports[$a][1],$id)!==false)
                    $count++;
            } elseif($post->data->user_reports[$a][1]==$id)
                $count++;
        return $count;
    }

    function flare_has_word($post,$word){
        return strpos(strtolower($post->data->link_flair_text),$word)!==false;
    }

    function match($post)
    {
        switch ($this->action) {
            case 'flair_spam':
            case 'flair_repost_spam':
            case 'flair_nopulse_spam':
                if ($this->flare_has_word($post,'spam'))
                    return false;
                break;
            case 'flair_clear_spam':
                if($post->data->link_flair_text=='')
                    return false;
                break;
        }
        return eval($this->eval);
    }

    function do_eval() {
        return eval($this->eval);
    }

}

//Init bot
require_once ('bot_login_details.php');
$my_bot = new reddit_bot(
    bot_login_details::$user_name,
    bot_login_details::$password,
    bot_login_details::$client_id,
    bot_login_details::$client_secret);

//Add Rules
//TODO:add fail_id to array for flair rules
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'unmoderated',
    'action'=>'remove',
    'mod_note'=>'no upvotes',
    'reason_id'=>'12shmkmyn5v44',
    'check'=>'created_utc<'.(time()-60*60*2).'&&score<2');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'remove',
    'mod_note'=>'reported < 10',
    'reason_id'=>'140pankj60nvh',
    'check'=>'num_reports>0&&score<11');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots',
    'page'=>'reports','action'=>'remove',
    'mod_note'=>'reported < 20',
    'reason_id'=>'140pankj60nvh',
    'check'=>'num_reports>1&&score<21');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'remove',
    'mod_note'=>'reported, ratio',
    'reason_id'=>'140pankj60nvh',
    'check'=>'(score/num_reports)<100');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'flair_spam',
    'flair_template_id'=>'c251ea22-ca14-11ea-9e84-0ed2938c6f25',
    'check'=>'(spam_reports>2)&&score<300');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'flair_repost_spam',
    'flair_template_id'=>'07dc98c4-ca26-11ea-a0c0-0ee57b03db9b',
    'check'=>'(spam_repost_reports>1)&&score<200');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'flair_nopulse_spam',
    'flair_template_id'=>'77f66740-d06e-11ea-badd-0ecd8d2ebc75',
    'check'=>'(spam_nopulse_reports>1)&&score<200');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'reports',
    'action'=>'flair_clear_spam',
    'flair_template_id'=>'',
    'check'=>'flared_as_spam&&score>300');
$my_bot->rules[]=array(
    'sub'=>'pulsatingcumshots','page'=>'log',
    'action'=>'ban_temp',
    'ban_reason'=>'Spam',
    'duration'=>2,
    'ban_message'=>'2 day ban for spamming. I am a bot message the admin if you do not agree.',
    'mod_note'=>'too much spam',
    'check'=>'array_count(spamlink_count,"value>4")');

//Process
$my_bot->login();
$my_bot->process_rules();
$my_bot->logout();

//Report
echo date("H:i F jS,Y", strtotime('now')).' Rules Parsed:'.$my_bot->rules_parsed.'/'.count($my_bot->rules).', '.($my_bot->removed+$my_bot->edited).'/'.$my_bot->checked.' (edits/tests)'.PHP_EOL;
?>