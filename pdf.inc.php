<?php 

function map2Object($data){
	$data = trim($data);
	$result = false;
	if(substr($data, 0, 2) == "<<"){				// Value is another dictionary
		$result = new pdfDictionary($data);
	} else {
		switch (substr($data, 0, 1)){
			case "[":								// Value is an array
				$result = new pdfArray($data);	
				break;
			case "<":								// Value is a string (hex)
			case "(":								// Value is a string (lit)
				$result = new pdfString($data);	
				break;
			case "/":								// Value is a name
				$result = substr($data, 1);
				break;
			default:
				$result = $data;				// If nothing special, paste value one on one
		}
	}
	if(!$result){							// If one of the objects returned false,
		return $data;						// paste value one on one
	} else {
		return $result;
	}
}

function extractObjectNumber($data){
	$data = trim($data);
	$patObjectNumber = "/[0-9]+(?= [0-9]+ (obj|R))/i";
	
	if(preg_match($patObjectNumber, $data, $matches)){
		return $matches[0];
	}
}

class PDFRipper{
	public $version;		// String with PDF version number
	public $pages;
	public $objects;		// indirectObject: All indirect objects
		
	private $startxref;		// String with startXref value (byte offset of Xref table)
	private $trailer;		// dictionary: Starting point
	private $root;			// dictionary: Root object of the pdf
	
	private $patVersion 	= "/(?<=%PDF-)[0-9]+\.[0-9]+(?=\R)/i";
	private $patStartXref 	= "/(?<=startxref)\R[0-9]+/i";
	private $patTrailer 	= "/(?<=trailer)\R.*/i";
	private $patObject		= "/[0-9]+ [0-9]+ obj.*endobj/ismU";
	
	public function __construct($filePath){
		// Open the file.
		$hFile = fopen($filePath, "rb");
		$data = fread($hFile, filesize($filePath));
		fclose($hFile);
		
		$this->extractVersion($data);
		$this->extractStartXref($data);
		$this->extractTrailer($data);
		$this->extractObjects($data);
		
		// look for root object.
		$this->root = $this->objects[extractObjectNumber($this->trailer[0]->lookup("Root"))];
		// Gather pages.
		// for now only 1 page PDFs...
		$this->pages = $this->objects[extractObjectNumber($this->root->dictionary->lookup("Pages"))];
	}
	
	private function extractVersion(&$data){
		if(!preg_match($this->patVersion, $data, $matches)){
			throw new Exception("No version found"); // Throw error, because there needs to be a version number.
		} else {
			$this->version = $matches[0];
			return $this->version;
		}
	}
	
	private function extractStartXref(&$data){
		if(!preg_match($this->patStartXref, $data, $matches)){
			throw new Exception("No startxref found"); 
		} else {
			// Trim the results, because \R in regex assertion (lookback) causes problems.
			foreach($matches as $key => $match){
				$matches[$key] = trim($match);
			}
			$this->startxref = $matches;
			return $this->startxref;
		}
	}
	
	private function extractTrailer(&$data){
		if(!preg_match($this->patTrailer, $data, $matches)){
			throw new Exception("No trailer found"); //TODO: check versions 1.5+
		} else {
			// Trim the results, because \R in regex assertion (lookback) causes problems.
			foreach($matches as $key => $match){
				$matches[$key] = new pdfDictionary(trim($match));
			}
			$this->trailer = $matches;
			return $this->trailer;
		}
	}
	
	private function extractObjects(&$data){
		// Multiple objects possible, so preg_match_all
		if(!preg_match_all($this->patObject, $data, $matches)){
			throw new Exception("No objects found"); 
		} else {
			// Trim the results, because \R in regex assertion (lookback) causes problems.
			foreach($matches[0] as $key => $match){
				$newObject = new pdfObject(trim($match));
				$this->objects[$newObject->objectNumber] = $newObject;
			}
			return $this->objects;
		}
	}
	
}

class pdfString{
	public $type;		// Literal or Hexadecimal
	public $rawValue;	// Data as found in the pdf
	public $value;		// Decoded data
	
	public function __construct($data){
		$data = trim($data); // Just to be sure the data doesn't start with a new line or CR symbol.
		//echo "==".$data;
		// Check if the data starts with the correct delimiters
		switch(substr($data, 0, 1)){
			case "(":
				// It has to end with the same delimiter too.
				if (substr($data, -1) == ")"){
					$this->type = "lit";		//Literal string
				} else {
					throw new Exception ("String not complete \")\" missing");
				}
				break;
			case "<":
				if (substr($data, 0, 2) == "<<"){
					return false; // Its not a string its a dictionary.
				}
				// It has to end with the same delimiter too.
				if (substr($data, -1) == ">"){
					$this->type = "hex";		//Hexadecimal string
				} else {
					throw new Exception ("String not complete \">\" missing");
				}
				break;
			default:
				return false;
		}
		// Set the rawValue
		$this->rawValue = substr($data, 1, -1);
		// "Calculate" and set the value
		$this->value = $this->calculateValue($this->rawValue);
	}
	
	private function calculateValue($data){
		switch($this->type){
			case "lit":
				// Just remove the newline slashes.
				return stripslashes($data);
				break;
			case "hex":
				// Check if even or add 0 at the end.
				if (strlen($data) % 2 != 0){
					$data = $data."0";
				}
				$result = "";
				// Map to asscii chars.
				foreach(str_split($data, 2) as $chunk){
					$result .= chr(hexdec($chunk));
				}
				return $result;
				break;
		}
		return false;
	}
}

class pdfDictionary{
	public $pairs = array();
	
	//private $patPairs = "#/.*(?:<<.*>>|(?: |)\[.*\]|\(.*\)| .*|/.*)(?=/|$)#ismUX";
	private $patPairs = "#/(.*)(<<.*>>|(?: |)\[.*\]|(?: |)\(.*\)|(?: ).*(?=/|(?:>>))|/.*(?=/|(?:>>)))#ismU";
	
	public function __construct($data){
		$data = trim($data); // Just to be sure the data doesn't start with a new line or CR symbol.
		if(substr($data,0,2) == "<<" && substr($data,-2) == ">>"){
			//$data = substr($data,2,-2);
			//echo $data."\n";
			if(preg_match_all($this->patPairs, $data, $matches)){
				//print_r($matches);
				foreach($matches[0] as $match){	
					$this->pairs[] = new pdfDictionaryPair($match);
				}
			}
		} else {
			return false;
		}		
	}
	
	public function lookup($strKey){
		foreach ($this->pairs as $item){
			if($item->key == $strKey){
				return $item->value;
			}
		}
		return false;
	}
}

class pdfDictionaryPair{
	public $key;
	public $value;
	
	private $patKey = "#(?<=/)(.*)(?=<<| |/|\[|\()#iU";
	
	public function __construct($data){
		$data = trim($data);
		//echo $data."\n";
		if(preg_match($this->patKey, $data, $matches)){
			$this->key = $matches[0];						// Get the key
			$value = substr($data,strlen($this->key)+1);	// Get the value
			//echo $value."\n";
			$this->value = map2Object($value);
		}
	}
}

class pdfArray{
	public $items = array();
	
	private $patNextItem = "/^([0-9]+ [0-9]+ R|\[.*\]|<<.*>>|<.*>|\(.*\)|[^ ]*).*$/i";
	
	public function __construct($data){
		$data = trim($data);
		if(substr($data, 0, 1) == "[" && substr($data, -1) == "]"){
			$data = substr($data, 1, -1);
			while ($data != ""):
				$data = trim($data);
				if(preg_match($this->patNextItem, $data, $matches)){
					$nextItem = $matches[1];
					$this->items[] = map2Object($nextItem);
					$data = substr($data, strlen($nextItem));
				} else {
					break;
				}
			endwhile;
		} else {
			return false;
		}
	}
}

class pdfObject{
	public $objectNumber;
	public $dictionary;
	public $stream = null;
	
	private $patObjectNumber = "/[0-9]+(?= [0-9]+ obj)/i";
	private $patDictionary 	= "/<<.*>>/ism";
	//private $patString		= "/(\(|\<)(.*)(?:\)|\>)/ismU";
	//private $patArray		= "/\[.*\]/ism";
	private $patStream		= "/(?<=stream).*(?=endstream)/ism";
	
	public function __construct($data){
		$this->objectNumber = extractObjectNumber($data);
		if(preg_match($this->patDictionary, $data, $matches)){
			$this->dictionary = new pdfDictionary($matches[0]);
		}
		if(preg_match($this->patStream, $data, $matches)){
			$this->stream = trim($matches[0]);
		}
	}
	
	public function decodeStream(){
		if(!$this->stream == null){
			return gzuncompress($this->stream);
		}
	}
}

class pdfText{
	public $Td;
	public $Tm;
	public $Tf;
	public $Tj;
	
	private $patTd = "/([-+0-9.]+) ([-+0-9.]+) Td/i";
	private $patTm = "/([-+0-9.]+) ([-+0-9.]+) ([-+0-9.]+) ([-+0-9.]+) ([-+0-9.]+) ([-+0-9.]+) Tm/i";
	private $patTf = "/\/(F[0-9]+) ([0-9]+) Tf/i";
	private $patTj = "/\((.*)\)Tj/i";
	
	public function __construct($data){
		if(preg_match($this->patTd, $data, $matches)){
			$this->Td["x"] = $matches[1];
			$this->Td["y"] = $matches[2];
		}
		if(preg_match($this->patTm, $data, $matches)){
			$this->Tm["a"] = $matches[1];
			$this->Tm["b"] = $matches[2];
			$this->Tm["c"] = $matches[3];
			$this->Tm["d"] = $matches[4];
			$this->Tm["e"] = $matches[5];
			$this->Tm["f"] = $matches[6];
		}
		if(preg_match($this->patTf, $data, $matches)){
			$this->Tf["font"] = $matches[1];
			$this->Tf["size"] = $matches[2];
		}
		if(preg_match($this->patTj, $data, $matches)){
			$this->Tj = $matches[1];
		}
	}
}

class pdfFont2UnicodeTable{
	private $table;
	
	private $patBfrange = "/beginbfrange(.*)endbfrange/ism";
	private $patBfrangeItem = "/^<(.*)><(.*)><(.*)>$/ismU";
	
	public function __construct($data){
		if(preg_match($this->patBfrange, $data, $matches)){
			$tableData = trim($matches[1]);
			preg_match_all($this->patBfrangeItem, $tableData, $rowMatches);
			foreach($rowMatches[0] as $key => $value){
				$this->table[ltrim($rowMatches[1][$key], "0")] = $rowMatches[3][$key];
			}
		}
	}
	
	public function textToUnicode($pdfText){
		$data = str_split(stripslashes($pdfText->Tj), 1);
		$result;
		foreach($data as $key => $char){
			$hexChar = dechex(ord($char));
			if(isset($this->table[$hexChar])){
				$result[] = chr(hexdec($this->table[$hexChar]));
			}
		}
		return implode($result);
	}
}
?>