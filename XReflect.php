<?php
/**
 * XReflect - Object Oriented PHP Reflection in XML (generator)
 *
 * @package XReflect
 * @author Przemek Sobstel http://sobstel.org (2008)
 * @license New BSD License
 * @link http://segfaultlabs.com/XReflect/ Official Project Page
 * @link http://segfaultlabs.com/XReflect/schema/ ReflectX schema
 *
 * @todo (generating) writing to xml
 * @todo reading from (generated) xml
 * @todo graphviz support
 * @todo support for @method, @property, @example, @inheritdoc and inline {@link}.
 * @todo command line tool
 * @todo phpdoc converter (as an complete alternative for this tool)
 * @todo option to make generated xml look beautifuly formed - import to dom do the thing
 * @todo support for new php3 features
 * @todo revise Reflection API for new features
 */

class XReflect
{
	
	/**
	 * @var SimpleXMLElement
	 */
	protected $doc;
	
	/**
	 * @var array
	 */
	protected $classes = array();
	
	/**
	 * @var array
	 */
	protected $constantsDocs = array();
		
	/**
	 * @var string Base path which is stripped from the beginning of "file" element
	 */
	protected $docRoot = '/';
	
	/**
	 * Constructor 
	 */
	public function __construct()
	{				
		spl_autoload_register(array($this, 'autoload'));
	}
	
	public function autoload($className)
	{
		if (isset($this->classes[$className]))
		{
			include_once $this->classes[$className];
			return true;
		}
		else
		{
			return false;
		}
	}		
	
	/**
	 * Set base path which is stripped from the beginning of "file" element
	 *
	 * Note! Output file path beginning is simlpy cut off by. 
	 * Length of the cut is simply fileBasePath string length.
	 *  
	 * @param string
	 */
	public function setDocRoot($docRoot)
	{
		$this->docRoot = $docRoot;
	}
		
	/**
	 * Add path with files with classes to be documented
	 * 
	 * Finds all classes. 
	 * Also reads constants phpdoc comments (as they are not provided by Reflection API).
	 * 
	 * All files are read first with file_get_contents(), and only the (auto)loaded, because 
	 * of dependencies on parent classes. Otherwise, PHP parser would raise errors as it couldn't find it. 
	 * 
	 * @param string Path to directory with files to be documented
	 * @param string File pattern (full regex)
	 * @param string Class name pattern (full regex)
	 */
	public function addClasses($path, $filePattern = '#\.php$#', $classPattern = '#.*#')
	{
		if (!file_exists($path) || !is_dir($path))
		{
			throw new XReflect_Exception('Path provided in addClass() does not exist or is not directory ('.$path.')');
		}
		
		// find all files
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
		
		foreach ($iterator as $file)
		{
			$fileName = $file->getPathname();
			if (preg_match($filePattern, $fileName))
			{
				// init some vars
				$curClass = $curDocComment = null;
				
				// get all tokens from given file
				$tokens = token_get_all(file_get_contents($fileName));
				
				// find classes and constants
				while (list(, $token) = each($tokens))
				{
					if (is_array($token))
					{
						switch ($token[0])
						{
							case T_CLASS : 
							case T_INTERFACE :
								next($tokens); // skip whitespace
								list(, $class) = current($tokens);
								
								// used to read constants and should be null if pattern not matched
								// to prevent adding constant from unmatched class to previous class
								$curClass = null;  
								
								if (preg_match($classPattern, $class))
								{
									$this->classes[$class] = $fileName;
															
									// used to read constants for given class
									$curClass = $class;
								}
							break;

							case T_CONST :																
								if (!is_null($curDocComment))
								{
									next($tokens); // skip whitespace
									list(, $constant) = current($tokens);
									
									$this->constantsDocs[$curClass.'::'.$constant] = $curDocComment;
									$curDocComment = null; 
								}
							break;

							case T_DOC_COMMENT :
								$curDocComment = $token[1];
							break;
							
							case T_WHITESPACE : // look below
							break;
							
							default: // to prevent setting wrong docs (defined earlier) 
								$curDocComment = null;
							break;
						}
					}										
				}
			}
		}
		
		ksort($this->classes);		
	}

	public function reflect($filename = null)
	{
		$doc = new SimpleXMLElement(
			'<?xml version="1.0" encoding="utf-8"?><xreflect xmlns="http://segfaultlabs.com/xphpdoc/"></xreflect>'
		);
					
		// class/interface parsing
		$this->reflectClasses($doc);

		// to string
		$output = $doc->asXML();

		// return/save output
		return !is_null($filename) ? file_put_contents($filename, $output) : $output;
	}

	/**
	 * @param SimpleXMLElement
	 */
	protected function reflectClasses(SimpleXMLElement $element)
	{
		foreach ($this->classes as $name => $file)
		{
			$refClass = new ReflectionClass($name);
			$isInterface = $refClass->isInterface();
			
			$refDoc = $this->getDocComment($refClass->getDocComment());

			// class/interface
			$type = (!$isInterface ? 'class' : 'interface');
			$class = $element->addChild($type);

			// id
			$class['id'] = $type.'.'.$name;

			// name
			$class->name = $name;
			
			// class specific (abstract, final, extends, implements)
			if (!$isInterface)
			{
				// abstract
				if ($refClass->isAbstract())
				{
					$class['abstract'] = '1';
				}

				// final
				$this->reflectFinal($refClass, $class);

				// extends
				$parentRefClass = $refClass->getParentClass();
				if ($parentRefClass)
				{
					$class->extends = $parentRefClass->getName();
						
					if ($parentRefClass->isUserDefined())
					{
						$class['userDefined'] = '1';
					}
				}

				// implements
				foreach ($refClass->getInterfaces() as $refIClass)
				{
					$class->implements = $refIClass->getName();
						
					if ($refIClass->isUserDefined())
					{
						$class['userDefined'] = '1';
					}
				}
			}			

			// constants
			$this->reflectConstants($refClass, $class);			
			
			// properties
			$this->reflectProperties($refClass, $class);

			// methods
			$this->reflectMethods($refClass, $class);
								
			// summary
			$this->reflectSummary($refDoc, $class);
			
			// desc
			$this->reflectDesc($refDoc, $class);
						
			// file
			$this->reflectFile($refClass, $class);

			// category
			$this->reflectCategory($refDoc, $class);
			
			// package
			$this->reflectPackage($refDoc, $class);
			
			// subpackage
			$this->reflectSubpackage($refDoc, $class);
			
			// version
			$this->reflectVersion($refDoc, $class);
			
			// deprecated
			$this->reflectDeprecated($refDoc, $class);
			
			// since
			$this->reflectSince($refDoc, $class);
			
			// author(s)
			$this->reflectAuthor($refDoc, $class);
			
			// copyright
			$this->reflectCopyright($refDoc, $class);
			
			// license
			$this->reflectLicense($refDoc, $class);
			
			// internal
			$this->reflectInternal($refDoc, $class);
			
			// link(s)
			$this->reflectLink($refDoc, $class);			
			
			// phpdoc unknown tags
			$knownTags = array(
				'category', 'package', 'subpackage',
				'version', 'deprecated', 'since',
				'author', 'copyright', 'license',			
				'internal', 'link'
			);	
			$this->reflectUnknownTags($refDoc, $class, $knownTags); 
		}
	}

	protected function reflectConstants(ReflectionClass $refClass, SimpleXMLElement $element)
	{
		$constants = $refClass->getConstants();
		
		// eliminate inherited constants
		$parentRefClass = $refClass;
		while ($parentRefClass = $parentRefClass->getParentClass())
		{
			$constants = array_diff_key($constants, $parentRefClass->getConstants());
		}
		
		$constantsEl = $element->addChild('constants');

		foreach ($constants as $name => $value)
		{
			$constant = $constantsEl->addChild('constant');

			$constant->name = $name;
			$constant->value = $value;
			
			$classConstant = $refClass->getName().'::'.$name;			
			if (isset($this->constantsDocs[$classConstant]))
			{
				$refDoc = $this->getDocComment($this->constantsDocs[$classConstant]);
				
				$this->reflectSummary($refDoc, $constant);
				$this->reflectDesc($refDoc, $constant);
			}
		}
	}
	

	protected function reflectProperties(ReflectionClass $refClass, SimpleXMLElement $element)
	{
		$classId = $element['id'];

		// default values
		$defaultValues = array();

		foreach (array_merge($refClass->getDefaultProperties(), $refClass->getStaticProperties()) as $k => $v)
		{
			if ($v)
			{
				$defaultValues[$k] = $v;
			}
		}

		$propertiesEl = $element->addChild('properties');
		
		// properties
		foreach ($refClass->getProperties() as $refProperty)
		{
			$name = $refProperty->getName();
			$refDoc = $this->getDocComment($refProperty->getDocComment());
				
			$property = $propertiesEl->addChild('property');
				
			// id
			$property['id'] = $classId.'.property.'.$name;

			// static
			$this->reflectStatic($refProperty, $property);

			// access
			$this->reflectAccess($refProperty, $property);
				
			// name
			$property->name = $name;

			// type (from phpdoc @var tag)
			$this->reflectType($refDoc, $property); // type 
				
			// default value
			if (isset($defaultValues[$name]))
			{
				$property->value =  $this->varExport($defaultValues[$name]);
			}
				
			// summary
			$this->reflectSummary($refDoc, $property);
			
			// desc
			$this->reflectDesc($refDoc, $property);
			
			// deprecated
			$this->reflectDeprecated($refDoc, $property);
			
			// since
			$this->reflectSince($refDoc, $property);
			
			// internal
			$this->reflectInternal($refDoc, $property);
								
			// phpdoc unknown tags
			$knownTags = array(
				'deprecated', 'internal', 'link', 'since', 'var'
			);	
			$this->reflectUnknownTags($refDoc, $property, $knownTags);
		}
	}	
	
	protected function reflectMethods(ReflectionClass $refClass, SimpleXMLElement $element)
	{
		$classId = $element['id'];
		
		$methodsEl = $element->addChild('methods');

		foreach ($refClass->getMethods() as $refMethod)
		{
			if ($refMethod->isUserDefined())
			{
				$refDoc = $this->getDocComment($refMethod->getDocComment());
				
				$name = $refMethod->getName();
					
				// method
				$method = $methodsEl->addChild('method');

				// id
				$method['id'] = $classId.'.method.'.$name;

				// abstract
				if ($refMethod->isAbstract())
				{
					$method['abstract'] = '1';
				}

				// final
				$this->reflectFinal($refMethod, $method);

				// static
				$this->reflectStatic($refMethod, $method);

				// access
				$this->reflectAccess($refMethod, $method);
				
				// returns reference
				if ($refMethod->returnsReference())
				{
					$n['returnsReference'] = '1';
				}				
				
				// name
				$method->name = $name;
				
				// params
				$this->reflectParams($refMethod, $method);

				// it can add type and summary to existing params (if not present)
				// @todo				
				
				// return - element return { type & summary? }? &
				// @todo
				
				// throws = element throws { name & summary? }* &
				// @todo

				// summary
				$this->reflectSummary($refDoc, $method);
				
				// desc
				$this->reflectDesc($refDoc, $method);
				
				// files
				$this->reflectFile($refMethod, $method, true);

			
				// deprecated
				$this->reflectDeprecated($refDoc, $method);
				
				// since
				$this->reflectSince($refDoc, $method);				
				
				// authors
				$this->reflectAuthor($refDoc, $method);
				
				// internal
				$this->reflectInternal($refDoc, $method);
				
				// link(s)
				$this->reflectLink($refDoc, $method);			
				
				// phpdoc unknown tags
				$knownTags = array(
					'author', 'deprecated', 'internal', 'link', 'param', 'since'
				);	
				$this->reflectUnknownTags($refDoc, $method, $knownTags); 				
			}
		}	
	}

	protected function reflectParams(Reflector $ref, SimpleXMLElement $element)
	{
		$paramsEl = $element->addChild('params');
			
		foreach ($ref->getParameters() as $i => $refParam)
		{
			$param = $paramsEl->addChild('param');
				
			// passed by reference
			if ($refParam->isPassedByReference())
			{
				$param['passedByReference'] = '1';
			}
				
			// name
			$param->name = $refParam->getName();

			// type
			if ($refClass = $refParam->getClass())
			{
				$param->type = $refClass->getName();
			}
			elseif ($refParam->isArray())
			{
				$param->type = 'array';
			}
				
			// value
			if ($refParam->isOptional())
			{
				$param->value = $this->varExport($refParam->getDefaultValue());
			}
		}
	}
		
	protected function reflectFinal(Reflector $ref, SimpleXMLElement $element)
	{
		if ($ref->isFinal())
		{
			$element['final'] = '1';
		}
	}

	protected function reflectStatic(Reflector $ref, SimpleXMLElement $element)
	{
		if ($ref->isStatic())
		{
			$element['static'] = '1';
		}
	}
	
	protected function reflectAccess(Reflector $ref, SimpleXMLElement $element)
	{
		if ($ref->isPublic())
		{
			$element['access'] = 'public';
		}
		elseif ($ref->isProtected())
		{
			$element['access'] = 'protected';
		}
		elseif ($ref->isPrivate())
		{
			$element['access'] = 'private';
		}
	}	
			
	/**
	 * reflect type (phpdoc @var tag)
	 */
	protected function reflectType(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($type = $refDoc->getType())
		{
			$element->type = $type;
		}
	}	
	
	protected function reflectSummary(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{	
		if ($summary = $refDoc->getSummary())
		{
			$element->summary = $summary;
		}
	}
	
	protected function reflectDesc(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($desc = $refDoc->getDesc())
		{
			$element->desc = $desc;
		}
	}
	
	protected function reflectFile(Reflector $ref, SimpleXMLElement $element, $omitFileName = false)
	{
		$file = $element->addChild('file');

		if (!$omitFileName)
		{
			$file->fileName = substr($ref->getFileName(), strlen($this->docRoot));
		}

		$file->startLine = $ref->getStartLine();

		$file->endLine = $ref->getEndLine();
	}	
	
	protected function reflectCategory(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($category = $refDoc->getCategory())
		{
			$element->category = $category;
		}
	}
	
	protected function reflectPackage(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($package = $refDoc->getPackage())
		{
			$element->package = $package;
		}
	}
	
	protected function reflectSubpackage(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($subpackage = $refDoc->getSubpackage())
		{
			$element->subpackage = $subpackage;
		}
	}
	
	protected function reflectVersion(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($version = $refDoc->getVersion())
		{
			$element->version = $version;
		}
		
	}
		
	protected function reflectDeprecated(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($deprecated = $refDoc->getDeprecated())
		{
			$element->deprecated = $deprecated;
		}	
	}
	
	protected function reflectSince(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($since = $refDoc->getSince())
		{
			$element->since = $since;
		}
	}
	
	protected function reflectAuthor(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($authors = $refDoc->getAuthors())
		{
			$authorsEl = $element->addChild('authors');
			
			foreach ($authors as $author)
			{
				$authorEl = $authorsEl->addChild('author');
				
				if (!empty($author['name']))
				{
					$authorEl->name = $author['name'];
				}
				
				if (!empty($author['email']))
				{
					$authorEl->email = $author['email'];
				}
				
				if (!empty($author['www']))
				{
					$authorEl->www = $author['www'];
				}
			}
		}
	}
	
	protected function reflectCopyright(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		if ($copyright = $refDoc->getCopyright())
		{
			$element->copyright = $copyright;
		}		
	}
	
	protected function reflectLicense(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{

	}
				
	protected function reflectInternal(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{			
		// phpdoc: @internal
		if ($internal = $refDoc->getInternal())
		{
			$element->internal = $internal;
		}	
	}
	
	protected function reflectLink(XReflect_DocComment $refDoc, SimpleXMLElement $element)
	{
		$links = $refDoc->getLinks();
		
		if (!empty($links))
		{
			foreach ($links as $link)
			{
				$a = $element->addChild('link', $link['text']);
				$a['uri'] = $link['uri'];
			}
		}		
	}
		
	/**
	 * @param Reflection
	 * @param SimpleXMLElement
	 * @param array
	 */
	protected function reflectUnknownTags(XReflect_DocComment $refDoc, SimpleXMLElement $element, array $knownTags = array())
	{
		$unknownTags = $refDoc->getUnknownTags($knownTags);	
		
		if (!empty($unknownTags))
		{
			foreach ($unknownTags as $name => $values)
			{
				foreach ((array)$values as $value)
				{
					$unknownTag = $element->addChild('unknownTag', $value);
					$unknownTag['tag'] = $name;
				}
			}
		}		
	}

	/**
	 * @param string
	 * @return XReflect_DocComment
	 */
	protected function getDocComment($docCommentBlock)
	{
		return new XReflect_DocComment($docCommentBlock);
	}
	
	/**
	 * @param mixed
	 * @return string
	 */
	protected function varExport($var)
	{
		return is_null($var) ? 'null' : (is_scalar($var) ? $var : var_export($var, true));
	}

}


/**
 * Handles phpdoc comments
 *
 * @internal Quick and dirty
 */
class XReflect_DocComment
{

	/**
	 * @var string
	 */
	protected $summary = '';

	protected $desc = '';

	protected $tags = array();

	public function __construct($docCommentBlock)
	{
		$this->reflect($docCommentBlock);
	}

	/**
	 * @return string
	 */
	public function getSummary()
	{
		return $this->summary;
	}

	/**
	 * @return string
	 */
	public function getDesc()
	{
		return $this->desc;
	}
	
	/**
	 * @return array
	 */
	public function getAuthors()
	{
		$authors = array();

		foreach ($this->getTags('author') as $author)
		{
			$name = $email = $www = null;
				
			$parts = explode(' ', $author);
				
			foreach ($parts as $part)
			{
				if (strpos($part, 'http://') === 0)
				{
					$www = $part;
				}
				elseif (strpos($part, '@') !== false)
				{
					$email = $part;
				}
				else
				{
					if (!empty($name))
					{
						$part = ' '.$part;
					}
					
					$name .= $part;
				}
			}

			$authors[] = array(
				'name' => $name,
				'email' => $email,
				'www' => $www
			);
		}

		return $authors;
	}	
	
	public function getCategory()
	{
		$categoryTags = $this->getTags('category');

		return end($categoryTags);		
	}

	public function getCopyright()
	{
		$copyrightTags = $this->getTags('copyright');

		return end($copyrightTags);
	}	
	
	/**
	 * @return string
	 */
	public function getDeprecated()
	{
		$deprecatedTags = $this->getTags('deprecated');

		return end($deprecatedTags);
	}	

	/**
	 * @return string
	 */
	public function getInternal()
	{
		$internalTags = $this->getTags('internal');

		return implode(PHP_EOL, $internalTags);
	}

	public function getLinks()
	{
		$links = array();
		
		$linkTags = $this->getTags('link');

		foreach ($linkTags as $linkTag)
		{
			$manyLinks = explode (',', $linkTag);
			
			foreach ($manyLinks as $link)
			{
				$uri = $text = null;
				
				$parts = explode(' ', trim($link), 2);				
				
				$uri = $parts[0];
				if (isset($parts[1]))
				{ 
					$text = $parts[1];
				} 
				
				$links[] = array(
					'uri' => $uri,
					'text' => $text
				);
			}
		}
		
		return $links;
	}
	
	public function getPackage()
	{
		$packageTags = $this->getTags('package');

		return end($packageTags);		
	}	
	
	/**
	 * @return string
	 */
	public function getSince()
	{
		$sinceTags = $this->getTags('since');

		return end($sinceTags);
	}

	public function getSubpackage()
	{
		$subpackageTags = $this->getTags('subpackage');

		return end($subpackageTags);		
	}
	
	/**
	 * @return string|null
	 */
	public function getType() // var type
	{
		$typeTags = $this->getTags('var');

		return end($typeTags);
	}
	
	public function getVersion()
	{
		$versionTags = $this->getTags('version');

		return end($versionTags);		
	}	

	/**
	 * Get phpdoc tags
	 *
	 * @param string optional
	 * @return array Empty array if no tags
	 */
	public function getTags($tag = null)
	{
		if (is_null($tag))
		{
			return $this->tags;
		}
		else
		{
			return isset($this->tags[$tag]) ? $this->tags[$tag] : array();
		}
	}

	/**
	 * @return array Associative array of unknown tags
	 */
	public function getUnknownTags($knownTags = array())
	{
		$unknownTags = array_diff_key($this->tags, array_flip($knownTags));

		return $unknownTags;
	}
	
	protected function reflect($docCommentBlock)
	{
		// parse off asterisks from beginnings of lines
		$docCommentBlock = preg_replace('#^\s*\*#m', '', $docCommentBlock);

		$lines = explode(PHP_EOL, $docCommentBlock);

		$summaryMode = $descMode = $tagMode = false;

		foreach ($lines as &$line)
		{
			$line = trim($line);
				
			if (($line == '/**') || ($line == '/'))
			{
				$line = '';

				continue;
			}
				
			if (empty($line))
			{
				$summaryMode = $tagMode = false;

				if ($descMode)
				{
					$line .= PHP_EOL.PHP_EOL;
				}
			}
			elseif ($line{0} == '@')
			{
				$descMode = false;

				$tagMode = true;

				$parts = explode(' ', $line, 2);

				$tag = substr($parts[0], 1);

				$line = (isset($parts[1]) ? $parts[1] : '');

				if (!array_key_exists($tag, $this->tags))
				{
					$this->tags[$tag] = array();
				}

				$nextKey = count($this->tags[$tag]);
				$this->tags[$tag][$nextKey] = '';
				$tagRef = &$this->tags[$tag][$nextKey];
			}
			else
			{
				if ($summaryMode || empty($this->summary)) // summary always first
				{
					$summaryMode = true;
				}
				else
				{
					if ($summaryMode === true) // turn off summary mode
					{
						$summaryMode = false;
					}
						
					$descMode = true;
				}
			}

			if ($summaryMode)
			{
				$this->summary .= $line;
			}
				
			if ($descMode)
			{
				$this->desc .= $line;
			}
				
			if ($tagMode)
			{
				$tagRef .= $line;
			}
				
		}

		// post processing

		$this->desc = rtrim($this->desc);

		// var - add desc
		if (!empty($this->tags['var']))
		{
			$var = array_pop($this->tags['var']);
				
			$parts = explode(' ', $var, 2);

			if (isset($parts[1]))
			{
				$this->desc .= $parts[1].PHP_EOL;
			}
				
			$this->tags['var'] = array($parts[0]);
		}
	}

}


/**
 * Exception class
 */
class XReflect_Exception extends Exception
{
}
