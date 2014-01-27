<?php
class SVN{
  public function get_status(){
    $info = `svn status --xml`;
    return new SimpleXMLElement($info);
  }
  public function get_info(){
    $info = `svn info --xml`;
    return new SimpleXMLElement($info);
  }
  public function get_url_log($url){
    $url_escaped = escapeshellarg($url);
    $info = `svn log --xml --stop-on-copy $url_escaped`;
    return new SimpleXMLElement($info);
  }
  public function get_revision_log($revision){
    $revision_escaped = (int)$revision;
    $info = `svn log --xml -v -r $revision_escaped`;
    return new SimpleXMLElement($info);
  }
}
class Logger{
  const ERROR=1;
  const IMPORTANT = 9;
  const INFO=10;
  private $level;
  public function __construct($level){
    $this->level = $level;
  }
  public function log($text,$priority){
    if($priority < $this->level){ 
      echo $text;
    }
  }
}
class Rebaser{
  private $svn;
  private $logger;
  private $plan_filename;
  public function __construct($svn,$logger){
    $this->svn = $svn;
    $this->logger = $logger;
  }
  private function has_local_changes(){
    try{
      $xml = $this->svn->get_status();
      return (bool)$xml->target->entry;
    }catch(Exception $e){
      $this->handle_parse_exception($e);
    }
  }
  private function handle_parse_excepion($e){
    $this->logger->log($e->__toString() . "\n",Logger::ERROR);
    exit(1);
  }
  private function get_working_copy_info(){
    try{
      $xml = $this->svn->get_info();
      $revision = (int)(string)$xml->entry['revision'];
      $url = (string)$xml->entry->url;
      $root = (string)$xml->entry->repository->root;
    }catch(Exception $e){
      $this->handle_parse_exception($e);
    }
    return array(
      'url' => $url,
      'revision' => $revision,
      'root' => $root,
    );
  }
  private function get_full_history($url){
    try{
      $xml = $this->svn->get_url_log($url);
      $history = array();
      foreach($xml->logentry as $logentry){
        $revision = (int)(string)$logentry['revision'];
        $author = (string)$logentry->author;
        $date = (string)$logentry->date;
        $msg = (string)$logentry->msg;
        $history[] = array(
          'author' => $author,
          'date' => $date,
          'msg' => $msg,
          'revision' => $revision,
        );
      }
      return array_reverse($history);
    }catch(Exception $e){
      $this->handle_parse_exception($e);
    }
  }
  private function get_copy_source($revision){
    try{
      $xml = $this->svn->get_revision_log($revision);
      return $xml->logentry->paths->path['copyfrom-path'];

    }catch(Exception $e){
      $this->handle_parse_exception($e);
    }
  }
  public function run(array $options){
    $this->plan_filename = array_key_exists('plan',$options)?$options['plan']:'.svn_rebase.plan';
    if(array_key_exists('continue',$options)){
      $this->execute_plan($options);
    }else{
      $this->prepare_plan($options);
    }
  }
  public function prepare_plan(array $options){
    if(file_exists($this->plan_filename)){
      $this->logger->log("The plan file {$this->plan_filename} already exists. If you like that plan, run again with --continue, otherwise delete the file, and run again.\n",Logger::ERROR);
      exit(4);
    }
    $wc_info = $this->get_working_copy_info();
    $this->logger->log("The URL of this branch is {$wc_info['url']} and revision of working copy is {$wc_info['revision']}.\n",Logger::INFO);
    if($this->has_local_changes()){
      $this->logger->log("There are local changes in the working copy. Make sure that `svn status` does not show anything, and run again.\n",Logger::ERROR);
      exit(2);
    }else{
      $this->logger->log("There are no local changes in the working copy.\n",Logger::INFO);
    }
    $history=$this->get_full_history($wc_info['url']);
    if(empty($history)){
      $this->logger->log("There are no commits to this branch in svn log.\n",Logger::INFO);
      exit(0);
    }
    $first_changeset = $history[0];
    $last_changeset = $history[count($history)-1];
    $this->logger->log("First commit was at revision {$first_changeset['revision']} and the last was at {$last_changeset['revision']}.\n",Logger::INFO);
    if($wc_info['revision'] < $last_changeset['revision']){
      $this->logger->log("Revision of the working copy ({$wc_info['revision']}) is lower than the last changeset ({$last_changeset['revision']}) for the url ({$wc_info['url']}). Perofrm `svn update`, and run again.\n",Logger::ERROR);
      exit(3);
    }
    $relative_source_path = $this->get_copy_source($first_changeset['revision']);
    $source_url = $wc_info['root']  . $relative_source_path;
    $this->logger->log("The branch originated from $source_url.\n",Logger::INFO);
    if(array_key_exists('source-url',$options)){
      $source_url = $options['source-url'];
      $this->logger->log("Due to --source-url option, we will use $source_url as a source for the rebased branch.\n",Logger::INFO);
    }
    if(array_key_exists('new-url',$options)){
      $new_url = $options['new-url'];
      $this->logger->log("Due to --new-url option, we will use $new_url as a name for the new rebased branch.\n",Logger::INFO);
    }else{
      $new_url = $wc_info['url'];
      $this->logger->log("We will delete $new_url and then recreate it again the new rebased branch. You can use --new-url to specify the name of new branch, if you want to keep the old branch intact.\n",Logger::INFO);
    }
    $plan = array();
    //TODO: actually, I should replace this if with `svn ls $new_url`
    if($new_url == $wc_info['url']){
      $plan[]=array(
        'command'=>'svn remove -m "Making sure that target branch does not exist before rebasing" ' . escapeshellarg($new_url),
        'comment'=>"Make sure that the $new_url does not exist."
      );
    }
    $plan[]=array(
      'command' => 'svn copy -m "Creating target branch for rebasing"  ' . escapeshellarg($source_url) . ' ' . escapeshellarg($new_url),
      'comment' => "Copying $source_url to $new_url."
    );
    $plan[]=array(
      'command' => 'svn switch '. escapeshellarg($new_url),
      'comment' => "Switching current working copy to latest version of $new_url which now has same content as $source_url"
    );
    $escaped_context = escapeshellarg($wc_info['url'] . '@' . (int)$last_changeset['revision']);
    
    if(array_key_exists('single-commit',$options)){
      $this->logger->log("Due to --single-commit option whole range of changes will be commited as a single commit.\n",Logger::INFO); 
      $plan[]=array(
        'command' => 'svn merge -r ' . (int)$first_changeset['revision'] . ':' . (int)$last_changeset['revision'] . ' ' . $escaped_context,
        'comment' => 'Merge all changesets at once.',
      );

      $unique_authors = array();
      foreach($history as $changeset){
        $unique_authors[$changeset['author']]=true;
      }
      $authors = join(" and ",array_keys($unique_authors));  
      $message = "Remerge of revisions {$first_changeset['revision']}:{$last_changeset['revision']} {$wc_info['url']}@{$last_changeset['revision']} by $authors since {$first_changeset['date']} to {$last_changeset['date']}.";
      $plan[]=array(
        'command' => 'svn commit -m ' . escapeshellarg($message),
        'comment' => 'Commit changeset.'
      );
    }else{
      $this->logger->log("All changesets will be recreated one-by-one. You can use --single-commit option to merge them all together.\n",Logger::INFO);
      foreach($history as $changeset){
        $plan[]=array(
          'command' => 'svn merge -c ' . (int)$changeset['revision'] . ' ' . $escaped_context,
          'comment' => "Merge changeset {$changeset['revision']}.",
        );
        
        $message = "Remerge of revision {$changeset['revision']} {$wc_info['url']}@{$last_changeset['revision']} by {$changeset['author']} at {$changeset['date']} -- {$changeset['msg']}";
        $plan[]=array(
          'command' => 'svn commit -m ' . escapeshellarg($message),
          'comment' => 'Commit changeset.'
        );
      
      }
    }
    $this->logger->log("The following plan will be saved to {$this->plan_filename} :\n",Logger::INFO);
    foreach($plan as $step){
      $this->logger->log("# {$step['comment']}\n",Logger::INFO);
      $this->logger->log("{$step['command']}\n",Logger::INFO);
    }
    $this->save_plan($plan);
    $this->logger->log("Plan saved to {$this->plan_filename}. Run with --continue to execute the plan.\n",Logger::IMPORTANT);
  }
  private function save_plan($plan){
    $xml = new DomDocument('1.0','utf-8');
    $xml->preserveWhiteSpace = false;
    $xml->formatOutput = true;
    $xml_plan = $xml->createElement('plan');
    foreach($plan as $step){
      $xml_step = $xml->createElement('step');
      foreach(array('comment','command') as $field){
        $xml_field_value = $xml->createTextNode($step[$field]);
        $xml_field = $xml->createElement($field);
        $xml_field->appendChild($xml_field_value);
        $xml_step->appendChild($xml_field);
      }
      $xml_plan->appendChild($xml_step);
    }
    $xml->appendChild($xml_plan);
    $xml->save($this->plan_filename);
  }
  private function execute_plan($options){
    if(!file_exists($this->plan_filename)){
      $this->logger->log("The plan file {$this->plan_filename} does not exist. You can specify a diffrent filename using --plan option, or run without --continue to create a plan.\n",Logger::ERROR);
      exit(5);
    }
    $plan = $this->load_plan();
    foreach($plan as $i=>$step){
      $comment = $step['comment'];
      $command = $step['command'];
      $this->logger->log("$comment\n",Logger::INFO);
      $this->logger->log("$command\n",Logger::IMPORTANT);
      $output = array();
      $return_value = 0;
      exec($command,$output,$return_value);
      $this->logger->log(join("\n",$output)."\n",Logger::INFO);
      if($return_value){
        $this->logger->log("Non-zero exit code $return_value. User intervention required. Once you fix the problem, run again with --continue\n",Logger::ERROR);
        exit(6);
      }else{
        $this->save_plan(array_slice($plan,1+$i));
        $this->logger->log("OK\n",Logger::INFO);
      }
    }
    $this->logger->log("Plan executed, removing {$this->plan_filename}.\n",Logger::INFO);
    unlink($this->plan_filename);
  }
  private function load_plan(){
    try{
      $xml = new SimpleXMLElement(file_get_contents($this->plan_filename));
      $plan = array();
      foreach($xml->step as $step){
        $comment = (string)$step->comment;
        $command = (string)$step->command;
        $plan[] = array(
          'comment' => $comment,
          'command' => $command,
        );
      }
      return $plan;
    }catch(Exception $e){
      $this->handle_parse_exception($e);
    }
  
  }
}
$svn = new SVN();
$logger = new Logger(100);
$rebaser = new Rebaser($svn,$logger);
$short_options = '';
$long_options = array(
  'source-url:',
  'new-url:',
  'message:',
  'plan:',
  'continue',
  'single-commit',
);
$rebaser->run(getopt($short_options,$long_options));
?>
