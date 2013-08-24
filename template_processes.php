<?php
class TemplateException extends Exception {
    //put your code here
}

/**
 * DEFINE MARKER PROCESSES FOR EMAIL TEMPLATE SYSTEM HERE
 */

class template_processes {
    private static $_processes = array();
    private static $_validation_functions = array();
    public static $no_change_marker = 'NO CHANGE';
    
    public static function get_processes(){
        if(!self::$_processes){
            self::_load();
        }
        return self::$_processes;
    }
    
    public static function get_validation_functions(){
        if(!self::$_validation_functions){
            self::_load();
        }
        return self::$_validation_functions;
    }
    
    private static function _load(){
        #can define reused callbacks here if it helps.
        $name_format = function($a){ return ucwords(strtolower($a)); };
        $check_date = function($d){
            if(!is_a($d,'date')){
                throw new TemplateException('Date not yet loaded into mocktest start time.');
            }
        };
        
        self::$_processes = array(
            /*
             * field name => array(require input,value method,callback)
             * 
             * required input can be either:
             *      1 : input is required (will show up in missing field if input not given)
             *      0 : corresponding item is not required in input array 
             *              (i.e. for functions that simply make environmental calls w/o arguments)
             *              N.B. that even object methods that don't take arguments 
             *                   require input b/c the input is the object itself.
             * value method can be either
             *      a) no change marker (returns the input value),
             *      b) a lambda taking at most one argument (the corresponding item
             *         from the input array, whether it be null or not),
             * or   c) an array of two items with: 
             *              1) a reference to the required object,
             *          and 2) the desired method as a string
             * 
             * callback can be any value which will satisfy call_user_func_array(callback,array(passed_value))
             */
            'address_block' => array(1,array('location','get_formatted')),
            'assignment_label' => array(1,array('assignment','get_label')),
            'attached_message' => array(1,self::$no_change_marker),
            'catesid' => array(1,array('compilation','get_catesid'),'strtoupper'),
            'contact_address' => array(0,function(){
                                            return system_values::get(
                                                                gatekeeper::check_login_type() == 'student' ? 
                                                                                'student_support_address' : 
                                                                                'staff_support_address'
                                                                ); 
                                            }), 
            'current_year' => array(0,function(){ return date('Y');}),#little bit of overkill, but the homogeneity keeps the control flow simpler
            'directions_link' => array(1,array('location','get_location_directions_link')),
            'download_href' => array(1,array('download','get_indirect_download_url')),
            'downloads' => array(1,function($dls){
                                    $num_dls = count($dls);
                                    $result = array();
                                    for($i = 0; $i < $num_dls; $i++){
                                        $dl = $dls[$i][0];
                                        $result[] = '<a href=\''.$dl->get_indirect_download_url().'\'>'.$dls[$i][1].'</a>';
                                    }
                                    return $result;
                                }),
            'due_date' => array(1,array('assignment','get_duedate'),function($d)use($check_date){
                                                                            if($d == 'Optional'){
                                                                                return $d;
                                                                            }
                                                                            if(!$d){
                                                                                return 'Optional';
                                                                            }
                                                                            $check_date($d);
                                                                            return $d->getMDY();
                                                                            }),
            'firstname' => array(1,array('recipient','get_firstname'),$name_format),
            'location_name' => array(1,array('location','get_location_name')),
            'mocktest_contact_address' => array(0,function(){
                                            return system_values::get('mocktests_support_address');
                                            }),
            'password' => array(1,array('recipient','get_decrypted_password')),
            'staff_address' => array(1,array('staff','get_primaryemail')),
            'staff_name' => array(1,array('staff','get_name'),$name_format),
            'start_time' => array(1,array('mocktest','get_mocktest_start_time'),function($d)use($check_date){
                                                                                    $check_date($d); 
                                                                                    return $d->format('g:i A');
                                                                                }),
            'student_instructions' => array(1,array('assignment','get_student_instructions'),function($si){ 
                                                                                                return $si ? $si : 'None';
                                                                                                }),
            'test_date' => array(1,array('mocktest','get_mocktest_start_time'),function($d)use($check_date){
                                                                                    $check_date($d); 
                                                                                    return $d->format('l, F jS');
                                                                                }),
            'test_type' => array(1,array('compilation','get_test_type_reference')),
            'timing' => array(1,array('assignment','get_timing')),
            'username' => array(1,array('recipient','get_username'),'strtolower')
        );
                                                                                
        self::$_validation_functions = array(
                #this array is used to verify input variables
                'downloads' => function($dls){
                                    foreach($dls as $dl){
                                        if(!is_a($dl[0],'download') || !is_string($dl[1])){
                                            return false;
                                        }
                                    }
                                    return true;
                                }
        );
    }
}
?>
