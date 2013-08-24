<?php
class email_template {
    protected static $_path;
    protected static $_components_path;
    
    protected static $_template_info = array(); #template_name => array(file_names)
    protected static $_file_cache = array(); #file_name => string of file_contents
    protected static $_file_markers = array(); #file_name => array(markers present)
    
    protected static $_marks = array('{','}');
    protected static $_loop_marks = array('~%','%~');
    
    protected static $_processes = array();
    protected static $_validation_functions = array();
     
    protected $_template_name;
    protected $_current_files = array();

    #following are only populated AFTER process_input
    public $erring = array(); 
    public $missing = array(); 
    public $output = '';
    public $subject = '';
    
    public $debug = FALSE;
    
    protected static function _load(){
        #get template-file associations
        if(!self::$_path){
            self::$_path = system_values::get('email_template_path');
            self::$_components_path = self::$_path.'components/';
            self::$_template_info = parse_ini_file(self::$_path.'template_info.ini',true); #load as multi-dimensional array
            $markers = parse_ini_file(self::$_path.'template_markers.ini',true);
            foreach($markers as $file_name => $info){
                self::$_file_markers[$file_name] = $info['markers'];
            }
            require_once(self::$_path.'template_processes.php'); //also loads TemplateException
            self::$_processes = template_processes::get_processes();
            self::$_validation_functions = template_processes::get_validation_functions();            
        }
    }
    
    public static function pick_assignment_template($mt,$dl,$msg){
        /*
         * Really, this should be a general method that takes the input array
         * and the event (i.e. assignment, invitation, registration), checks all
         * the extant information in the array with the premade validators and 
         * returns the name of the most in-depth template available. 
         * But I don't have the time.
         */
        $template = 'assignment';
        foreach(array(array($mt,'_mocktest'),
                      array($dl,'_download'),
                      array($msg,'_message'))
                as $test_value){
            if($test_value[0]){
                $template .= $test_value[1];
            }
        }
        return $template;
    }
    
    public function __construct($template_name){
        self::_load();

        if(!isset(self::$_template_info[$template_name])){
            throw new TemplateException('Unable to find record of requested template.');
        }
        
        $this->_template_name = $template_name;
        $this->_subject = self::$_template_info[$template_name]['files'];
        #build template
        $file_names = self::$_template_info[$template_name]['files'];
        $num_files = count($file_names);
        for($i = 0; $i < $num_files; $i++){
            $file_name = $file_names[$i];
            if(!isset(self::$_file_cache[$file_name])){
                $contents = file_get_contents(self::$_components_path.$file_name,TRUE);#true = using include path
                if(!$contents){
                    throw new TemplateException('File '.$file_name.' not found in '.self::$_components_path);
                }
                self::$_file_cache[$file_name] = $contents;
            }
            
            #ordinally the same
            $this->_current_files[] = self::$_file_cache[$file_name]; 
            $this->_current_markers[] = self::$_file_markers[$file_name];
        }
    }
   /* @param array $input Associative array on which function will run load processes
    * @return string $output Template HTML loaded with variables
    * 
    * Takes associative array of input items, accesses user designed template,
    * and, using user defined functions in processes file, tries to load values
    * into template.
    * 
    * Loads missing into $this->missing, errors into $this->erring, and output
    * into $this->output. 
    */
                
    public function process_input($input){
        
        #expects an associative array of variables (mainly objects)
        if(!$this->_current_files){
            throw new TemplateException('Template not yet loaded.');
        }
        #reset with new input
        $marker_process_cache = array();
        $this->output = '';
        $this->missing = array();
        $this->erring = array();
        
        $num_files = count($this->_current_files); 
                
        for($i = 0; $i < $num_files; $i++){
            $file = $this->_current_files[$i];
            $markers = $this->_current_markers[$i]; #markers known present within current file
            $num_markers = count($markers);

            $non_array_markers_results = array();  
            $array_markers_results = array();
            
            for($j = 0; $j < $num_markers; $j++){
                $marker = $markers[$j];
                if(!array_key_exists($marker,$marker_process_cache)){ #if the process isn't cached, then run it
                    if(!array_key_exists($marker,self::$_processes)){
                        throw new TemplateException('Marker listed without corresponding process: '.$marker);
                    }
                    #UNPACK PROCESS
                    $process = self::$_processes[$marker]; #process = array(input required,process proto_callable,optional callback)
                    $input_required = $process[0]; // flag whether (1) or not (0) to require a corresponding item in input array
                    $proto_callable = $process[1];
                    $final_callback = isset($process[2]) ? $process[2] : NULL;
                    
                    #DECLARE CERTAIN VARS
                    $validation = NULL;
                    $default_validation = NULL;
                    $after_validation_callback = NULL; #must itself return a callable for main process
                    $name = $marker; #may be overwritten if object
                    $call_with_input = FALSE;
                    
                    #BEGIN PROCESS
                    if(is_array($proto_callable)){ #then proto_callable has form array(key to correct object,method on that object)
                        $name = $proto_callable[0]; #overwrite name (generally means overwriting property with its object)
                        $method = $proto_callable[1];
                        $default_validation = #if none other given, just check method exists so we don't throw that wonderful fatal error
                                    function($obj)use($method){
                                        return method_exists($obj,$method);
                                    };
                        #this replaces pointer with the actual object, and 
                        #therefore makes it callable by call_user_func
                        $after_validation_callback = 
                            function($proto_callable,$input){
                                $proto_callable[0] = $input;
                                return $proto_callable;
                            };
                    }else{
                        $call_with_input = TRUE;
                    }
                    
                    if($input_required && (!array_key_exists($name,$input) || !$input[$name])){ #if the field isn't listed in the passed input, you're not even trying
                        $this->missing[] = $name;
                        continue;
                    }

                    $an_input = $input[$name];
                    if(isset(self::$_validation_functions[$marker])){ #load validation function if available (still arranged by marker, not name)
                        $validation = self::$_validation_functions[$marker];
                    }
                    if(!$validation && $default_validation){ #employ default if none given (if default given)
                        $validation = $default_validation;
                    }
                    if($validation){ #run validation on inputs
                        $result = call_user_func_array($validation,array($an_input));
                        if(!$result){ #you fail, we bail
                            $this->erring[]  = $marker;
                            if($this->debug){
                                echo($marker.' failed initial validation. <br />');
                            }
                            continue;
                        }
                    }
                    
                    #this allows us to make changes to the callable based on the validated input
                    $callable = $after_validation_callback ? 
                                            $after_validation_callback($proto_callable,$an_input) 
                                            : $proto_callable; 

                    try{
                        if($callable == template_processes::$no_change_marker){
                            $result = $an_input;
                        }else{
                            $result = call_user_func_array($callable,$call_with_input ?
                                                            array($an_input):
                                                            array());
                        }
                    }catch(TemplateException $e){
                        $this->erring[] = $marker;
                        if($this->debug){
                            echo($marker.' failed on main processing.<br /> Exception: '.$e->getMessage());
                        }
                        continue;
                    }

                    #if there was a callback included
                    if($final_callback){
                        try{
                            $result = call_user_func($final_callback,$result);
                        }catch(TemplateException $e){
                            $this->erring[] = $marker;
                            if($this->debug){
                                echo($marker.' failed at callback processing.<br /> Exception: '.$e->getMessage());
                            }
                            continue;
                        }
                    }
                    $marker_process_cache[$marker] = $result; #save to cache
                }#End processing of this input
                
                # Load from cache while sorting out array results and adding 
                # 'syntactic sugar' in prep for substring work
                $made_pretty = self::$_marks[0].$marker.self::$_marks[1]; #e.g. marker becomes {marker} as in template
                if(is_array($marker_process_cache[$marker])){
                    $array_markers_results[$made_pretty] = $marker_process_cache[$marker];
                }else{
                    $non_array_markers_results[$made_pretty] = $marker_process_cache[$marker];
                }
            }#End processing all input for this file
            #filter away non-responses
            $non_array_markers_results = array_filter($non_array_markers_results);
            $array_markers_results = array_filter($array_markers_results);
            
            #STRING MANIPULATION TIME!!!!!! Fuck. This shit is so dense and illegible.
            
            #we can right off replace all non array markers with results
            $file = str_replace(array_keys($non_array_markers_results),
                                array_values($non_array_markers_results),
                                $file);
            
            #then we have to deal with markers pointing to arrays, therefore implying a loop
            $len_start_loop = strlen(self::$_loop_marks[0]);
            $len_end_loop = strlen(self::$_loop_marks[1]); #me being anal about pulling function calls out of loops
            foreach($array_markers_results as $marker => $result){
                #first step, find the position of the marker to loop in the file
                $marker_pos = strpos($file,$marker);
                if($marker_pos === FALSE){
                    continue; #we fucked up and somehow this marker isn't present, but it ain't no biggie
                }
                
                #next, we have to figure out what the wrapper is -- e.g. it could look like ~%<li>{marker}</li>%~
                $start_loop = strrpos(substr($file,0,$marker_pos),
                                        self::$_loop_marks[0]); #searches back from marker position to find position of ~%
                $length = strpos(substr($file,$start_loop),
                                self::$_loop_marks[1])
                          +$len_start_loop; #searches forward to find end loop marker position and infer length
                $looped_phrase = substr($file,$start_loop,$length); #should now exactly be ~%<li>{marker}</li>%~
                $meat = substr($looped_phrase,$len_start_loop,$length-$len_end_loop-$len_start_loop); #<li>{marker}</li>
                $open_tag = substr($meat,0,strpos($meat,$marker));#<li>
                $end_tag = substr($meat,strlen($open_tag)+strlen($marker));#</li>
                $file = str_replace($looped_phrase,
                                    implode('',
                                            array_map( #map results using open tag and end tag
                                                    function($v)use($open_tag,$end_tag){
                                                            return $open_tag.$v.$end_tag;},
                                                    $result)),
                                    $file);
            }#end dealing with array results (whew)
            
            if(!$this->debug){
                #clean up loose ends:
                $file = preg_replace('#'.preg_quote(self::$_loop_marks[0]).'.*'.preg_quote(self::$_loop_marks[1]).'#','',$file);
                $file = preg_replace('#'.preg_quote(self::$_marks[0]).'.*'.preg_quote(self::$_marks[1]).'#','',$file);
            }
            $this->output .= $file;
        }
        return $this->output;
    }
    
}
?>
