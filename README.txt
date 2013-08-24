-------------------------------------
Joe's email template system (8/23/2013)
-------------------------------------

Basic templating system intended to simplify automated HTML emails within a larger system. No fancy eval within the template stuff, just template variables and looped variables. Flexible interfacing with objects means that at run time, you don't have to do anything more than pass the desired objects on over.

###### Creating a Template #######

1) Design the component files:

Write HTML files, breaking them up as you desire. Wrap terms to be replaced in curly brackets and any html to be replaced with a looped array in '~%' and '%~'. Place the component files in the component folder.

As a little bit of a running example, we'll see how to make the template...

<h4>{heading}</h4>
<ul>
~%<li>{mah_list}</li>%~
</ul>
<strong>Goodbye, {name}.</strong>

become the HTML email...

<h4>My List</h4>
<ul>
   <li>FOO</li>
   <li>BAR</li>
</ul>
<strong>Goodbye, Jim.</strong>

2) Describe the template in template_info.ini:

List the template's components in order and define the email's subject line. That is...

[my_first_template]
subject = 'YOLO, customer. YOLO.'
files[] = 'otherfile.html'
files[] = 'body.html'

3) Describe the markers in template_markers.ini:

This saves A LOT of processing time and keeps you thorough. Note marker names can only refer to one process / input, but can be used multiple times.

[body.html]
markers[] = 'heading'
markers[] = 'mah_list'
markers[] = 'name'

4) Describe load processes in template_processes.php:

Code the load processes into in array. More detail within the file, but as an example...

//$input refers to the associate array the user passes in at runtime. 
array(
'heading' => array( 1, //means $input['header'] required for function
		  function($h){ 
			//do some operation to $input['header'] 
		   },
		   'strtoupper' //optional callback
		),
'mah_list' => array(1, //means $input['object_key'] required
	   	    array('object_key','foo_bar_method'),
		    //The above will call 'method' on $input['object_key'],
		    //and use the output to replace the 'mah_list' marker in the 		    //template		
		    function($ds){
			$fs = array();
			foreach($ds as $d){
				$fs[] = strtoupper($d);
			}
			return $fs;
			}
			//callbacks can be function name strings, 				 		//lambdas, or arrays for call_user_func
		),
'name' => array(1,
		template_processes::$no_change_marker //yep
		)
);

You can also define a validation process run on the input at template_processes::$_validation_processes. Note that by default, every object (e.g. $input['object_key']) will be checked for the requested method (if missing, it'll end up in the errors heap).

It would be bad form to add HTML in the processing. Leave it in the template.


5) Run it:

class purely_an_example {
	public function foo_bar_method(){
		return array('foo','bar');
	}
}

$input = array('heading' => 'My list',
		'mah_list' => new purely_an_example(),
		'name' => 'Jim'
);

$e_t = new email_template('my_first_template');

echo $e_t->process_input($input); 

// otherfile.html contents formatted and concatenated to body.html:
//
// <h4>My list</h4>
// <ul>
//   <li>FOO</li>
//   <li>BAR</li>
// </ul>
// <strong>Goodbye, Jim</strong>

echo $e_t->subject; //'YOLO, customer. YOLO.'

-------------------------------------

Base folder holds all the editable :

######## template_info.ini ########
Use this document to tell the system how to assemble various component files into one template and to set a default subject line.

######### template_markers.ini ########
This file describes the markers (i.e. the strings to be replaced) within each component file (note that the syntactical marks aren't included).

######### template_processes.php ########
Any marker listed in template_markers.ini needs to have an associated process for loading it. Defined validation, load, and callback aspects of the process here. Also defines TemplateException, which the system will raise at various points.

########## components folder ##########
Put component files in here.

-------------------------------------

The main file, optionally kept externally, is:

email_template.php


This last file is a class which constitutes the core logic of the program. That file can be kept anywhere, but must have a reference to the above directory.

In general, no editing within the class itself should be necessary, but possible reasons include changing reference to directory or template markers.