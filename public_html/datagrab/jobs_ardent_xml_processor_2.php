<?php
/**
 * @author Scott Fleming
 * cURL data from feed, and process the description, requirements and experience
 * elements to wrap the text as a nl2br so that they appear properly when displayed on
 * page. Data is 'scrubbed' and set correctly for use on the website.Files are stored
 * in the datagrab directory for import with ExpressionEngine.
 *
 */

$iniConf            = parse_ini_file($_SERVER['DOCUMENT_ROOT'] .'/../conf/' . php_uname('n') . '.ini', true);
$feed_url           = $iniConf['datagrab']['new_feed_url'];

// cURL the XML file and stick it in the data grab directory, overwriting the existing file.

$feedXml = curl_get_contents($feed_url);
file_put_contents("jobs_raw_2.xml", $feedXml);

// Var assignment
$filexml='jobs_raw_2.xml';

// Build me an army worthy of Mordor!
$xml = new DOMDocument('1.0','utf-8');
$xml->formatOutput = true;
$xml->preserveWhiteSpace = false;
$xml->load($filexml); // load up the XML data

#load our xpath
$xpath = new \DOMXPath($xml);

$jobs       = $xml->getElementsByTagName('job');
$counter    = $xml->getElementsByTagName('job')->length;
$fac = array();

echo "<h2>Processing " . $counter . " job records in XML feed</h2>";
echo "<hr>";

// Loop through the jobs, and fix stuff, and make stuff more
// human readable and nice and tidy for datagrabbing it into EE.

$replaceArr = array(
    "'", "Hosptial"
);

$replaceWith = array(
    "", "Hospital"
);

// Load content filters, to remove unwanted HTML entities, tags and
// other 'things' that ruin the display of the jobDescription content.
// Typically, disabling any of the MsWord markup which is being
// entered into the application field, coming to us all dirty and nasty.

$unwanted = array(
    '/<\?xml[^>]+\/>/im',
    '%face="[^"]+"%i',
    '%color="[^"]+"%i',
    '%class="[^"]+"%i',
    '%style="[^"]+"%i',
    '%size="[^"]+"%i',
    '%height="[^"]+"%i',
    '%width="[^"]+"%i',
    '%hspace="[^"]+"%i',
    '%vspace="[^"]+"%i',
    '/\&nbsp\;/',
    '/(<font[^>]*>)|(<\/font>)/',
    '/(<o:p[^>]*>)|(<\/o:p>)/',
    '/(<span[^>]*>)|(<\/span>)/',
    '/(<h2[^>]*>)|(<\/h2>)/',
    '/\r|\n/'

);



foreach( $jobs as $key => $values){

    // create new job element within our loop.
    $myJob = $xml->getElementsByTagName('job')->item($key);

    # create jobID element
    $j = $xml->createElement('jobId', $myJob->getAttribute('id'));
    $myJob->appendChild($j);

    # Applicant Type node (internal or external)
    foreach($values->getElementsByTagName('internal') as $appType){
        $a = $xml->createElement('jobApplicationType', $appType->nodeValue == "No" ? "external" : "internal");
        $myJob->appendChild($a);
        $appType->parentNode->removeChild($appType);
    }

    # Create our Executive node
    foreach($values->getElementsByTagName('nmisc2') as $nmisc2) {
        $e = $xml->createElement('Executive', $nmisc2->nodeValue);
        $myJob->appendChild($e);
        # Kill the nmisc3 node, we're done with it.
        $nmisc2->parentNode->removeChild($nmisc2);
    }

    # Create our corporate/IT node
    foreach($values->getElementsByTagName('nmisc3') as $nmisc3) {
        $c = $xml->createElement('Corporate', $nmisc3->nodeValue);
        $myJob->appendChild($c);
        # Kill the nmisc3 node, we're done with it.
        $nmisc3->parentNode->removeChild($nmisc3);
    }

    // facility information (nested name, city, state, remove info element)
    foreach($values->getElementsByTagName('facility') as $facility){

        # Create new elements from the facility nested nodes
        foreach($facility->childNodes as $node){
            if($node->nodeName != "info"){

                # Keep the facililty state uppercase, others get proper case with replacements for spelling.
                $dataContent = $node->nodeName == 'state' ? $node->nodeValue : str_replace($replaceArr,$replaceWith, ucwords( strtolower($node->nodeValue) ) );
                $t = $xml->createElement('facility_'. $node->nodeName , $dataContent );
                $myJob->appendChild($t);

                #  echo $node->nodeName . ": " . $dataContent . "<br/>";
            }
        }

        # Create a new facility_id element and store it.
        $facid  = $facility->getAttribute('id');
        $f      = $xml->createElement('facilityId', $facid);
        $myJob->appendChild($f);

        # Remove the original facility node, as we broke it out.
        $facility->parentNode->removeChild($facility);

    }

    # datePosted record
    foreach( $values->getElementsByTagName('datePosted') as $date){
        $cdata = $xml->createCDATASection($date->nodeValue);
        $date->replaceChild($cdata, $date->childNodes->item(0));
    }


    // strip out any dashes in the title, as EE hates them more than I do! -sf
    foreach ($values->getElementsByTagName('jobTitle') as $title) {

        $cdata = $xml->createCDATASection( str_replace('-', '~',  $title->nodeValue) );
        $title->replaceChild($cdata, $title->childNodes->item(0));

        $url  = $xml->createElement("new_url_title", strtolower( preg_replace("/[^A-Za-z0-9]/", "", $title->nodeValue) . $j->nodeValue ));
        $myJob->appendChild($url);

    }

    # Description data, convert to CDATA and
    # htmlentities conversion to HTML tags.

    foreach ($values->getElementsByTagName('description') as $description) {

        $myJob->appendChild(
            $xml->createElement('jobDescription'))
            ->appendChild(
              $xml->createCDATASection( preg_replace($unwanted, ' ', html_entity_decode( $description->nodeValue ))
            )
        );

        $description->parentNode->removeChild($description);

    }

    // Create our new_url_title for importing into the datagrab
    // insert the node into the end of this <job> element after we make sure
    // the data is url friendly and doesn't contain illegal characters.  --sf


    foreach($values->getElementsByTagName('reqNum') as $referencenumber){
    }

    # Convert htmlentities into real URL
    foreach($values->getElementsByTagName('url') as $url){
        $cdata = $xml->createCDATASection(  html_entity_decode($url->nodeValue) );
        $url->replaceChild($cdata, $url->childNodes->item(0));
    }

    # pattern match on word boundary only for "(or)" in department.
    $deptFilters = array('/\b(or)/i');
    $deptReplace = array('OR');

    // Display Department and clean up the HTML entities convert to CDATA text.
    foreach($values->getElementsByTagName('department') as $department){
        if($department->nodeValue){
            $cdata = $xml->createCDATASection( preg_replace($deptFilters, $deptReplace , html_entity_decode( ucwords( strtolower( $department->nodeValue ) ) ))) ;
            $department->replaceChild($cdata, $department->childNodes->item(0));
        }
    }

}

// Stuff the file in the datagrab directory
htmlentities($xml->save('jobs_processed_2.xml'));

# Let's take what we have, and re-hash it all so we can clean it up
# for export.
$xpath                      = new DOMXPath($xml);

# Load the nodes by 'job' node.
$nodes                      = $xpath->evaluate('/*/job');

    foreach ($nodes as $node){

        $corpExec  = $node->getElementsByTagName('Corporate');
        $exec      = $node->getElementsByTagName('Executive');
        $jobTitle  = $node->getElementsByTagName('jobTitle');

        # If either Corporate or Executive values have a yes, keep that node, toss all others
       if ( $corpExec->item(0)->nodeValue != 'Yes' && $exec->item(0)->nodeValue != 'Yes'){
            $node->parentNode->removeChild($node);
        }else{

           # Create jobNcat node and set the value based on the value.
           $jobNCat   = $xml->createElement('jobNcat', $exec->item(0)->nodeValue == 'Yes' ? $exec->item(0)->nodeName : $corpExec->item(0)->nodeName );
           $node->appendChild($jobNCat);

           echo $jobTitle->item(0)->nodeName . " = " . $jobTitle->item(0)->nodeValue . " " . $jobNCat->nodeName . " = ".  $jobNCat->nodeValue . "<br/>";

       }
    }

# Save the file, which is what we'll feed into Datagrab..
htmlentities($xml->save('jobs_filtered.xml'));


function curl_get_contents($url) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);

    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}