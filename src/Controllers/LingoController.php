<?php

namespace ctf0\Lingo\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class LingoController extends Controller
{
    use Ops;

    protected $lang_path;
    protected $langDirs;
    protected $file;
    protected $default_locale;

    public function __construct(Filesystem $file)
    {
        $this->lang_path = resource_path() . '/lang';
        $this->langDirs  = $file->directories($this->lang_path);
        $this->file      = $file;
    }

    public function index()
    {
        return view('Lingo::lingo-' . env('MIX_LINGO_FRAMEWORK'));
    }

    /**
     * [getFiles description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function getFiles(Request $request)
    {
        $vendor   = $request->dir_name ? true : false;
        $dir_name = $vendor
            ? "{$this->lang_path}/vendor/{$request->dir_name}"
            : $this->lang_path;

        $def_dir = $this->getDefaultLangDir($dir_name, $vendor);

        // new vendor / no files
        if (!$def_dir) {
            return $this->badResponse('NaN');
        }

        $files = $this->file->files($def_dir);

        $res = [];

        foreach ($files as $key) {
            $res[] = substr($key, strrpos($key, '/') + 1);
        }

        return $this->goodResponse($res);
    }

    /**
     * [getVendorDirs description].
     *
     * @return [type] [description]
     */
    public function getVendorDirs()
    {
        $all_dirs = '';

        foreach ($this->langDirs as $dir) {
            if (ends_with($dir, 'vendor')) {
                $all_dirs = $this->file->directories($dir);

                break;
            }
        }

        $res = [];

        foreach ($all_dirs as $key) {
            $res[] = substr($key, strrpos($key, '/') + 1);
        }

        return $res;
    }

    /**
     * [getFileData description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function getFileData(Request $request)
    {
        $file_name = $request->file_name;
        $vendor    = $request->dir_name ? true : false;
        $dir_name  = $vendor
            ? "{$this->lang_path}/vendor/{$request->dir_name}"
            : $this->lang_path;

        $locales = $this->getLocales($dir_name, $vendor);

        // new vendor / no locales
        if (!$locales) {
            return $this->badResponse('NaN');
        }

        // new vendor / have locales
        if (!$file_name) {
            return $this->goodResponse(compact('locales'));
        }

        $def_locale    = $locales[0];
        $def_file      = include "$dir_name/$def_locale/$file_name";
        $def_file_keys = array_keys($def_file);
        $res           = [];
        $nesting       = false;

        // no files to extract keys
        // fuck javascript
        if (empty($def_file_keys)) {
            return $this->goodResponse([
                'locales'=> $locales,
                'nesting'=> $nesting,
                'all'    => (object) $res,
            ]);
        }

        foreach ($locales as $code) {
            foreach ($def_file_keys as $key) {
                if ($def_locale == $code) {
                    $data = array_get($def_file, $key);

                    // disable nesting arrays
                    if (!is_array($data)) {
                        $res[$key][$code] = $data;
                    } else {
                        $nesting = true;
                    }

                    continue;
                }

                $inc  = include "$dir_name/$code/$file_name";
                $data = array_get($inc, $key);

                // disable nesting arrays
                if (!is_array($data)) {
                    $res[$key][$code] = $data;
                } else {
                    $nesting = true;
                }
            }
        }

        return $this->goodResponse([
            'locales'=> $locales,
            'nesting'=> $nesting,
            'all'    => $res,
        ]);
    }

    /**
     * [scanForMissing description].
     *
     * @return [type] [description]
     */
    public function scanForMissing()
    {
        Artisan::call('langman:sync');

        return $this->goodResponse('Done');
    }

    /**
     * [addNewTrans description].
     *
     * @param Request $request [description]
     */
    public function addNewLocale(Request $request)
    {
        $code     = $request->file_name;
        $vendor   = $request->dir_name ? true : false;
        $dir_name = $vendor
            ? "{$this->lang_path}/vendor/{$request->dir_name}"
            : $this->lang_path;

        $def_dir = $this->getDefaultLangDir($dir_name, $vendor);
        $new_dir = "$dir_name/$code";

        if ($this->file->exists($new_dir)) {
            return $this->badResponse("'$new_dir' Directory Already Exists");
        }

        if (!$def_dir) {
            if ($this->file->makeDirectory($new_dir)) {
                return $this->goodResponse("'{$request->dir_name}/$code' Was Successfully Created");
            }
        } else {
            if ($this->file->copyDirectory($def_dir, $new_dir)) {
                return $this->goodResponse("'{$request->dir_name}/$code' Was Successfully Created");
            }
        }

        return $this->badResponse();
    }

    /**
     * [addNewFile description].
     *
     * @param Request $request [description]
     */
    public function addNewFile(Request $request)
    {
        $file_name = $request->file_name;
        $dir_name  = $request->dir_name;

        $dirs = $dir_name
            ? $this->file->directories("{$this->lang_path}/vendor/$dir_name")
            : $this->langDirs;

        foreach ($dirs as $locale) {
            if (!$dir_name && ends_with($locale, 'vendor')) {
                continue;
            }

            if ($this->file->exists("$locale/$file_name")) {
                return $this->badResponse("'$file_name' Already Exists At '$locale'");
            }

            $this->file->put("$locale/$file_name", "<?php\n\nreturn [];");
        }

        return $dir_name
            ? $this->goodResponse("'$dir_name/../$file_name' Was Successfully Created")
            : $this->goodResponse("'lang/../$file_name' Was Successfully Created");
    }

    /**
     * [addNewVendor description].
     *
     * @param Request $request [description]
     */
    public function addNewVendor(Request $request)
    {
        $dir_name   = $request->dir_name;
        $path       = "{$this->lang_path}/vendor/$dir_name";
        $def_locale = config('app.locale');

        if ($this->file->exists($path)) {
            return $this->badResponse("'vendor/$dir_name' Already Exists");
        }

        if ($this->file->makeDirectory($path)) {
            return $this->goodResponse("'vendor/$dir_name' Was Successfully Created");
        }

        return $this->badResponse();
    }

    /**
     * [deleteFile description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function deleteFile(Request $request)
    {
        $file_name = $request->file_name;
        $dir_name  = $request->dir_name;

        $dirs = $dir_name
            ? $this->file->directories("{$this->lang_path}/vendor/$dir_name")
            : $this->langDirs;

        $success = true;

        foreach ($dirs as $locale) {
            if (!$dir_name && ends_with($locale, 'vendor')) {
                continue;
            }

            $sucess = $this->file->delete("$locale/$file_name") ? true : false;
        }

        return true == $success
            ? $this->goodResponse("'$file_name' Was Successfully Deleted")
            : $this->badResponse();
    }

    /**
     * [deleteLocale description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function deleteLocale(Request $request)
    {
        $locale   = $request->locale;
        $dir_name =  $request->dir_name;

        $path = $dir_name
            ? "{$this->lang_path}/vendor/$dir_name"
            : $this->lang_path;

        return $this->file->deleteDirectory("$path/$locale")
            ? $this->goodResponse("'$locale' Was Successfully Deleted")
            : $this->badResponse();
    }

    /**
     * [deleteVendor description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function deleteVendor(Request $request)
    {
        $dir_name =  $request->dir_name;
        $path     = "{$this->lang_path}/vendor/$dir_name";

        return $this->file->deleteDirectory($path)
            ? $this->goodResponse("'vendor/$dir_name' Was Successfully Deleted")
            : $this->badResponse();
    }

    /**
     * [saveFileData description].
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function saveFileData(Request $request)
    {
        $dir_name  = $request->dir_name;
        $file_name = $request->file_name;
        $data      = $request->data;

        if ($this->saveToFile($file_name, $data, $dir_name)) {
            return $dir_name
                ? $this->goodResponse("'$dir_name/$file_name' Was Successfully Saved")
                : $this->goodResponse("'$file_name' Was Successfully Saved");
        }

        return $this->badResponse();
    }
}
