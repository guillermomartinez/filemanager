<?php
namespace Php\Filemanager;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Intervention\Image\ImageManager;

class Filemanager
{
	private $config;
	public $accion = '';
	public $path = '';
	public $log = null;
	private $info = array("data"=>array(),"status"=>true,"msg"=>array("query"=>"","params"=>array()));
	private $fileDetails = array(
				"path" => '',
				"filename" => "",
				"filetype" => "",
				"filemtime" => "",
				"filectime" => "",
				"readable" => 0,
				"writable" => 0,
				"preview" => "",				
				"size" => "",
				"msg" => "",
				);

	public function __construct($extra=array()){
		$this->config = array(
			"doc_root" => "",
			"separator" => "userfiles",
			"debug" => true,
			"debugfile" => "log/filemanager.log",
			"ext" => array("jpg","jpeg","gif","png","svg","txt","pdf","odp","ods","odt","rtf","doc","docx","xls","xlsx","ppt","pptx","csv","ogv","mp4","webm","m4v","ogg","mp3","wav","zip","rar"),
		    "upload" => array(
		        "number" => 5,
		        "overwrite" => false,
		        "size_max" => 10
		    ),
		  	"images" => array(
		        "images_ext" => array("jpg","jpeg","gif","png"),
		        "resize" => array("thumbWidth" => 80,"thumbHeight" => 80)
		    ),
		);
		// if($this->config["doc_root"]=="") $this->config["doc_root"] = $_SERVER['DOCUMENT_ROOT'];
		if(isset($_SERVER['DOCUMENT_ROOT'])) $this->config['doc_root'] = $_SERVER['DOCUMENT_ROOT'];		
		if(count($extra)>0) $this->setup($extra);
		if($this->config['debug']){
			$this->log = new Logger('filemanager');
			$this->log->pushHandler(new StreamHandler($this->config['debugfile']));
		}
		// var_dump($this->config);
	}

	/**
	 * Cambia las configuraciones
	 * 
	 * @param array $extra Configuraciones a modificar
	 * @return void
	*/
	private function setup($extra){
		$this->config = array_replace_recursive($this->config,$extra);
	}

	/**
	 * Obtiene la ruta absoluta 
	 * 
	 * @return string
	*/
	public function getFullPath(){
		if($this->config['doc_root']=='')			
			return $this->config['separator'];
		else
			return $this->config['doc_root'].'/'.$this->config['separator'];
	}

	/**
	 * Valida la url path solo formato /directorio o /file.txt
	 * @param string $path 
	 * @return boolean
	 */
	public function validPath($path){
		if(preg_match_all("#^/{1}$|^/[a-z0-9A-Z]{1}([a-z0-9A-Z-._/])*(/|[a-z0-9A-Z-_]+[.]{1}[a-z0-9A-Z]{1,})+$#",$path) > 0){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Valida nombre de archivo
	 * @param string $filename 
	 * @return boolean
	 */
	public function validNameFile($filename){
		$filename = trim($filename);
		if($filename!="." && $filename!=".." && $filename!=" " && preg_match_all("#^[a-zA-Z0-9-_.\s]+$#",$filename) > 0){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Valida extension de archivos
	 * @param string $filename 
	 * @return boolean
	 */
	public function validExt($filename){
		$exts = $this->config['ext'];
		$ext = $this->getExtension($filename);
		if(array_search($ext,$exts)===false){
			return false;
		}else{
			return true;
		}


	}

	/**
	* Todas las propiedades de un archivo o directorio
	* 
	* @param SplFileInfo $file Object of SplFileInfo
	* @param string $path Ruta de la carpeta o archivo
	* @return array|null Lista de propiedades o null si no es leible
	*/
	public function fileInfo($file,$path){	
		if($file->isReadable()){
			$item = $this->fileDetails;
			$item["path"] = '/'.$this->config['separator'].$path;
			$item["filename"] = $file->getFilename();
			$item["filetype"] = $file->getExtension();
			$item["filemtime"] = $file->getMTime();
			$item["filectime"] = $file->getCTime();
			$item["size"] = $file->getSize();
			
			if($file->isDir()){
				$item["filetype"] = '';
				$item["url"] = $path.$item["filename"].'/';
				$item['preview'] = '';
			}elseif($file->isFile()){
				$item['preview'] = $item['path'].$item['filename'];
				$item["url"] = '?accion=getfolder&path='.$path.$item["filename"];
				$thumb =  $this->createThumb($file,$path);
				if($thumb){
					$item['preview'] = '/'.$this->config['separator'].'/_thumbs'.$path.$thumb;
				}
				// $item['path'] = str_replace($item['filename'],'',$item['path']);
				// var_dump($item['path']);
				if($file->isWritable()==false)
					$item['writable'] = 1;			
			}
			// var_dump($item);
			return $item;
		}else{
			return ;
		}			
	}

	/**
	 * Crea la miniatura de imagenes
	 * @param UploadedFile $file 
	 * @param string $path 
	 * @return string Nombre del nuevo archivo
	 */
	public function createThumb($file,$path){
		$ext = $file->getExtension();
		if(array_search($ext, $this->config["images"]["images_ext"])){
			$fullpath = $this->getFullPath().$path;
			$fullpaththumb = $this->getFullPath().'/_thumbs'.$path;
			$filename = $file->getFilename();
			$filename_new = $this->removeExtension($filename).'-'.$this->config['images']['resize']['thumbWidth'].'x'.$this->config['images']['resize']['thumbHeight'].'.'.$file->getExtension();
			$fullpaththumb_name = $this->getFullPath().'/_thumbs'.$path.$filename_new;
			if( $this->config['debug'] ) $this->_log(__METHOD__." - $fullpaththumb");
			$filethumb = new Filesystem;
			if($filethumb->exists($fullpaththumb_name) == false){			
				if( $this->config['debug'] ) $this->_log(__METHOD__." - ".$fullpaththumb_name);
				if($filethumb->exists($fullpaththumb) == false){
					$filethumb->mkdir($fullpaththumb);
				}
				$manager = new ImageManager();
				$image = $manager->make($fullpath.$file->getFilename())->fit($this->config['images']['resize']['thumbWidth'],$this->config['images']['resize']['thumbHeight'],function ($constraint) {$constraint->upsize();});
				$image->save($fullpaththumb_name);
			}
			return $filename_new;
		}
		
	}

	/**
	 * Crear la carpeta para subir de nivel
	 * @param string $path 
	 * @return array
	 */
	public function folderParent($path){	
		if($path != '/' && $path !="" ){			
			$params = explode("/",$path);
			$path = '/';
			$temp = array();
			foreach ($params as $key => $value) {
				if($value!=""){
					$temp[] = $value;
				}
			}
			$n = count($temp);
			if($n>1){
				for ($i=0; $i < $n-1 ; $i++) { 
					$path .= $temp[$i].'/';
				}
			}

		}
		$item = $this->fileDetails;
		$item["path"] = '/'.$this->config['separator'].$path;
		$item["url"] = $path;
		$item["filename"] = "";
		$item["filetype"] = "";
		$item['preview'] = $item['path'].$item['filename'];
		return $item;
	}
	
	/**
	* Lista carpeta y archivos segun el path ingresado, no recursivo, ordenado por tipo y nombre
	* 
	* @param string $path Ruta de la carpeta o archivo
	* @return array|null Listado de archivos o null si no existe
	*/
	public function getAllFiles($path){
			
		$fullpath = $this->getFullPath().$path;
		// var_dump(__DIR__);
		// var_dump($fullpath);
		if(file_exists($fullpath)){
			$file = new \SplFileInfo($fullpath);
			if($file->isDir()){
				$r = array();
				if($path != "/") $r[] = $this->folderParent($path);
				$finder = new Finder();
				$directories = $finder->notName('_thumbs')->depth('0')->sortByType()->in($fullpath);
				foreach ($directories as $key => $directorie) {
					$t = $this->fileInfo($directorie,$path);
					if($t) $r[] = $t;
				}
				return $r;
			}elseif($file->isFile()){					
				$t = $this->fileInfo($file,$path);
				if($t){
					return $t;
				}else{
					// $this->setInfo(array("msg"=>"Archivo no leible"));
					$result = array("query"=>"BE_GETFILEALL_NOT_LEIBLE","params"=>array());
					$this->setInfo(array("msg"=>$result));
					if( $this->config['debug'] ) $this->_log(__METHOD__." - Archivo no leible - $fullpath");
					return;
				} 
			}elseif($file->isLink()){
				// $this->setInfo(array("msg"=>"Archivo no permitido"));
				$result = array("query"=>"BE_GETFILEALL_NOT_PERMITIDO","params"=>array());
				$this->setInfo(array("msg"=>$result));
				if( $this->config['debug'] ) $this->_log(__METHOD__." - path desconocido - $fullpath");
				return ;
			}
		}else{
			// $this->setInfo(array("msg"=>"No existe el archivo"));
			$result = array("query"=>"BE_GETFILEALL_NOT_EXISTED","params"=>array());
			$this->setInfo(array("msg"=>$result));
			if( $this->config['debug'] ) $this->_log(__METHOD__." - No existe el archivo - $fullpath");
			return ;
		}

		
	}

	/**
	 * Agrega mensaje a cada peticion si se produce un error
	 * @param array $data 
	 * @return void
	 */
	public function setInfo($data=array()) {
		if(isset($data['data'])) $this->info['data'] = $data['data'];
		if(isset($data['status'])) $this->info['status'] = $data['status'];
		if(isset($data['msg'])) $this->info['msg'] = $data['msg'];
		
	}

	/**status
	 * Si debug active permite logear informacion
	 * @param string $string Texto a mostrar
	 * @return void
	 */
	public function _log($string) {
		$this->log->addError($string);
	}

	/**
	 * Clear var
	 * @param string $var 
	 * @return string
	 */
	private function sanitize($var) {
		$sanitized = strip_tags($var);
		$sanitized = str_replace('http://', '', $sanitized);
		$sanitized = str_replace('https://', '', $sanitized);
		$sanitized = str_replace('../', '', $sanitized);
		return $sanitized;
	}

	/**
	 * Remove extension
	 * @param string $filename 
	 * @return string
	 */
	public function removeExtension($filename)
	{
		return substr($filename,0,strrpos( $filename, '.' ) ) ;
	}

	/**
	 * Obtiene la extensión de un nombre de archivo
	 * @param string $nameFile 
	 * @return string
	 */
	public function getExtension($nameFile){
		$extension = substr( $nameFile, ( strrpos($nameFile, '.') + 1 ) ) ;
		$extension = strtolower( $extension ) ;
		return $extension;
	}

	/**
	 * Obtiene el tamaño maximo permitido por el servidor
	 * @return int
	 */
	public function getMaxUploadFileSize() {
			
		$upload_max_filesize =  ini_get('upload_max_filesize');
		$post_max_size =  ini_get('post_max_size');
		$size_max = min($upload_max_filesize, $post_max_size);
		// var_dump($upload_max_filesize, $post_max_size);
		// $this->_log(__METHOD__.": $size_max MB");

		return $size_max;
	}

	/**
	 * Mueve un arcivo subido
	 * @param UploadedFile $file 
	 * @param string $path 
	 * @return SplFileInfo|null
	 */
	public function upload($file,$path){
		if( $this->validExt($file->getClientOriginalName())){
			if($file->getClientSize() > ($this->getMaxUploadFileSize() * 1024 * 1024) ){
				// $this->setInfo(array("msg"=>"file size no permitido server: ".$file->getClientSize()));
				
				$result = array("query"=>"BE_UPLOAD_FILE_SIZE_NOT_SERVER","params"=>array($file->getClientSize()));
				$this->setInfo(array("msg"=>$result));

				if( $this->config['debug'] ) $this->_log(__METHOD__." - file size no permitido server: ".$file->getClientSize());
				return ;
			}elseif($file->getClientSize() > ($this->config['upload']['size_max'] * 1024 * 1024) ){
				// $this->setInfo(array("msg"=>"file size no permitido: ".$file->getClientSize()));

				$result = array("query"=>"BE_UPLOAD_FILE_SIZE_NOT_PERMITIDO","params"=>array($file->getClientSize()));
				$this->setInfo(array("msg"=>$result));

				if( $this->config['debug'] ) $this->_log(__METHOD__." - file size no permitido: ".$file->getClientSize());
				return ;
			}else{
				if($file->isValid()){
					$dir = $this->getFullPath().$path;
					$namefile = $file->getClientOriginalName();
					$namefile = $this->clearNameFile($namefile);
					$nametemp = $namefile;
					if( $this->config["upload"]["overwrite"] ==false){
						$ext = $file->getClientOriginalExtension();
						$i=0;
						while(true){
							$pathnametemp = $dir.$nametemp;
							if(file_exists($pathnametemp)){
								$i++;
								$nametemp = $this->removeExtension( $namefile ) . '_' . $i . '.' . $ext ;
							}else{
								break;
							}
						}
					}
					
					$file->move($dir,$nametemp);
					$file = new  \SplFileInfo($dir.$nametemp);
					return $file;		
				}
				
			}
		}else{
			// $this->setInfo(array("msg"=>"file no permitido","status"=>false));
			if( $this->config['debug'] ) $this->_log(__METHOD__." - file extension no permitido: ".$file->getExtension());
		}
		
		
	}

	/**
	 * Upload all files
	 * @param array $files 
	 * @param string $path 
	 * @return array|null
	 */
	public function uploadAll($files,$path){
		if( is_array($files) && count($files) > 0 ){
			$n = count($files);
			if( $n <= $this->config['upload']['number'] ){
				$res = array();
				$notresult = array();
				foreach ($files as $key => $value) {
					$file = $this->upload($value,$path);
					if( $file ){					
						$this->createThumb($file,$path);
						$res[] = $file->getFilename();
					}else{
						$notresult[] = $value->getClientOriginalName();
					}
				}
				$r = '';
				$n2 = count($res);			
				// $r = 'Subido: %s / %s';
				$result = array("query"=>"BE_UPLOADALL_UPLOADS %s / %s","params"=>array($n2,$n));
				if(count($notresult)>0){
					// $r .= ' | Not permitido: ';
					// $r .= implode(',', $notresult);
					$result['query'] = $result['query'].' | BE_UPLOADALL_NOT_UPLOADS '; 
					$i=0;
					$n = count($notresult);
					foreach ($notresult as $value) {
						$result['query'] = $result['query'] . ' %s';
						if( $n - 1 > $i ){
							$result['query'] = $result['query'] . ',';
							$value = $value.',';
						}
						$result['params'][] = $value;
						$i++;
						
					}
				}
				// $this->setInfo(array("msg"=>$r));
				
				$this->setInfo(array("msg"=>$result));
				return $res;
			}else{
				// $this->setInfo(array("msg"=>"Max upload: ".$this->config['upload']['number'],"status"=>false));
				$result = array("query"=>"BE_UPLOAD_MAX_UPLOAD %s MB","params"=>array($this->config['upload']['number']));
				$this->setInfo(array("msg"=>$result,"status"=>false));
				
			}
		}else{
			return ;
		}		
	}

	/**
	 * Limpia el nombre de archivo
	 * @param string $namefile 
	 * @return string
	 */
	public function clearNameFile($namefile){
		$namefile = strip_tags($namefile);
		$namefile = trim($namefile);
		$buscar = array("á","é","í","ó","ú","ñ","Ñ","Á","É","Í","Ó","Ú","ü","Ü");
		$reemplazar = array("a","e","i","o","u","n","n","a","e","i","o","u","u","U");
		$namefile = str_replace($buscar,$reemplazar,$namefile);
		$namefile = preg_replace("/[\s]+/", '-', $namefile);
		$namefile = preg_replace("/[^a-zA-Z0-9._-]/", '', $namefile);
		$namefile = strtolower($namefile);
		return $namefile;
	}

	/**
	 * Renombre una carpeta
	 * @param string $namefile 
	 * @param string $path 
	 * @return boolean
	 */
	public function newFolder($namefile,$path){
		$fullpath = $this->getFullPath().$path;
		$namefile = $this->clearNameFile($namefile);
		$dir = new Filesystem;
		if($dir->exists($fullpath.$namefile)){
			// $this->setInfo(array("msg"=>"Ya existe: ".$path.$namefile,"status"=>false));
			$result = array("query"=>"BE_NEW_FOLDER_EXISTED %s","params"=>array($path.$namefile));
			$this->setInfo(array("msg"=>$result,"status"=>false));

			if( $this->config['debug'] ) $this->_log(__METHOD__." - Ya existe: ".$path.$namefile);
			return false;
		}else{
			$dir->mkdir($fullpath.$namefile);
			// $this->setInfo(array("msg"=>"Creado: ".$path.$namefile,"data"=>array( "path" => $path, "namefile" => $namefile )));
			$result = array("query"=>"BE_NEW_FOLDER_CREATED %s","params"=>array($path.$namefile));
			$this->setInfo(array("msg"=>$result,"data"=>array( "path" => $path, "namefile" => $namefile )));
			return true;
		}
	}

	/**
	 * Borra un archivo
	 * @param string $namefile 
	 * @param string $path 
	 * @return void
	 */
	public function delete($namefile,$path){		
		$fullpath = $this->getFullPath().$path;
		$namefile = $this->clearNameFile($namefile);
		$file = new Filesystem;
		if($file->exists($fullpath.$namefile)){
			if( $this->config['debug'] ) $this->_log('$fullpath.$namefile - '.$fullpath.$namefile);
			if(is_dir($fullpath.$namefile)){
				$file->remove($fullpath.$namefile);
				// $this->setInfo(array("msg"=>'Archivo deleted'));
				$result = array("query"=>"BE_DELETE_DELETED","params"=>array());
				$this->setInfo(array("msg"=>$result));
			}elseif(is_file($fullpath.$namefile)){
				$file2 = new \SplFileInfo($fullpath.$namefile);				
				$filename = $file2->getFilename();
				$filename_new = $this->removeExtension($filename).'-'.$this->config['images']['resize']['thumbWidth'].'x'.$this->config['images']['resize']['thumbHeight'].'.'.$file2->getExtension();
				$fullpaththumb_name = $this->getFullPath().'/_thumbs'.$path.$filename_new;
				$file->remove($fullpaththumb_name);
				$file->remove($fullpath.$namefile);
				// $this->setInfo(array("msg"=>'Archivo deleted'));
				$result = array("query"=>"BE_DELETE_DELETED","params"=>array());
				$this->setInfo(array("msg"=>$result));
			}
		}else{
			// $this->setInfo(array("msg"=>'Archivo no existe: '.$namefile, "status"=> false));			
			$result = array("query"=>"BE_DELETE_NOT_EXIED","params"=>array());
			$this->setInfo(array("msg"=>$result, "status"=> false));
		}

	}

	/**
	 * Renombra un archivo
	 * @param string $nameold 
	 * @param string $namenew 
	 * @param string $path 
	 * @return void
	 */
	public function rename($nameold,$namenew,$path){		
		if($this->validNameFile($nameold) && $this->validNameFile($namenew)){
			$fullpath = $this->getFullPath().$path;
			$nameold = $this->clearNameFile($nameold);
			$namenew = $this->clearNameFile($namenew);
			
			$file = new Filesystem;
			if($file->exists($fullpath.$nameold)){
				if( $this->config['debug'] ) $this->_log('$fullpath.$nameold - '.$fullpath.$nameold);
				if(is_dir($fullpath.$nameold)){
					if($file->exists($fullpath.$namenew)==false){
						$file->rename($fullpath.$nameold,$fullpath.$namenew);
						// $this->setInfo(array("msg"=>'Archivo Modificaded'));
						$result = array("query"=>"BE_RENAME_MODIFIED","params"=>array());
						$this->setInfo(array("msg"=>$result));

					}else{
						// $this->setInfo(array("msg"=>'Ya existe'));
						$result = array("query"=>"BE_RENAME_EXISTED","params"=>array());
						$this->setInfo(array("msg"=>$result,"status"=>false));
					}

				}elseif(is_file($fullpath.$nameold)){
					$extold = $this->getExtension($nameold);
					$extnew = $this->getExtension($namenew);
					if( $extold != $extnew ){
						$result = array("query"=>"BE_RENAME_EXT_NOT_EQUALS","params"=>array());
						$this->setInfo(array("msg"=>$result,"status"=>false));
					}else{
						// $namenew = $namenew.'.'.$this->getExtension($nameold);
						if($file->exists($fullpath.$namenew)==false){
							$file2 = new \SplFileInfo($fullpath.$nameold);				
							if($file2->getExtension() == 'jpg' || $file2->getExtension() == 'jpeg' || $file2->getExtension() == 'png' || $file2->getExtension() == 'gif'){
								$filename = $file2->getFilename();
								$filename_new = $this->removeExtension($filename).'-'.$this->config['images']['resize']['thumbWidth'].'x'.$this->config['images']['resize']['thumbHeight'].'.'.$file2->getExtension();
								$fullpaththumb_name = $this->getFullPath().'/_thumbs'.$path.$filename_new;
								$file->remove($fullpaththumb_name);
							}

							$file->rename($fullpath.$nameold,$fullpath.$namenew);
							$file3 = new \SplFileInfo($fullpath.$namenew);				
							$this->createThumb($file3,$path);				
							// $this->setInfo(array("msg"=>'Archivo Modificaded'));
							$result = array("query"=>"BE_RENAME_MODIFIED","params"=>array());
							$this->setInfo(array("msg"=>$result));
						}else{
							// $this->setInfo(array("msg"=>'Ya existe'));
							$result = array("query"=>"BE_RENAME_EXISTED","params"=>array());
							$this->setInfo(array("msg"=>$result,"status"=>false));
						}
					}
					
					
				}
			}else{
				// $this->setInfo(array("msg"=>'Archivo no existe: '.$nameold, "status"=> false));
				$result = array("query"=>"BE_RENAME_NOT_EXISTS","params"=>array());
				$this->setInfo(array("msg"=>$result, "status"=> false));
			}
		}else{
			$result = array("query"=>"BE_RENAME_FILENAME_NOT_VALID","params"=>array());
			$this->setInfo(array("msg"=>$result, "status"=> false));
		}
		

	}

	/**
	 * Ejecuta todo las configuraciones
	 * @return JsonResponse
	 */
	public function run(){
		$request = Request::createFromGlobals();
		$request->getPathInfo();
		// var_dump($request->getMethod());
		// var_dump($request->query->all());
		// var_dump($request->request->all());
		// var_dump($request->files->all());
		
		$this->accion = $this->sanitize($request->request->get('accion'));
		$path = $this->sanitize($request->request->get('path'));
		
		$jsonResponse = new JsonResponse;
		if($this->validPath($path)==false){
			// $this->setInfo(array("msg"=>'No valido $path: '.$path));
			$result = array("query"=>"BE_RUN_NOT_VALID %s","params"=>array($path));
			$this->setInfo(array("msg"=>$result));

			if( $this->config['debug'] ) $this->_log(__METHOD__.' - No valido $path: '.$path);
		}else{
			// var_dump($request->getMethod());
			if($request->getMethod()=='POST'){
				if($this->accion==='getfolder'){
					$folders = $this->getAllFiles($path);
					if(is_array($folders))				
						$this->setInfo(array( "data" => $folders ));
				}elseif($this->accion==='getinfo'){
					$folders = $this->getAllFiles($path);
					if(is_array($folders))		
						$this->setInfo(array( "data" => $folders ));
				}elseif($this->accion==='uploadfile' ){
					$files = $this->uploadAll($request->files->get('file'),$path);
					if($files)		
						$this->setInfo(array( "data" => $files ));
				}elseif($this->accion==='newfolder'){
					$name = $this->sanitize($request->request->get('name'));
					$this->newFolder($name,$path);

				}elseif($this->accion==='renamefile'){
					$nameold = $this->sanitize($request->request->get('nameold'));
					$namenew = $this->sanitize($request->request->get('name'));					
					$this->rename($nameold,$namenew,$path);
				}elseif($this->accion==='deletefile'){
					$name = $this->sanitize($request->request->get('name'));
					$this->delete($name,$path);
				}
			}
		}
		$jsonResponse->setData($this->info);
		return $jsonResponse->sendContent();
	}
}
?>