<?php

namespace think\addons;

use fast\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\Db;
use think\Exception;
use ZipArchive;

/**
 * 插件服务
 * @package think\addons
 */
class Service
{

    /**
     * 远程下载地址
     * @var string 
     */
    protected static $remote_url = "http://www.fa.com/api/addon/download";

    /**
     * 插件外非安全目录检测
     * @var array 
     */
    protected static $check_dirs = ['application', 'public'];

    /**
     * 远程下载插件
     * 
     * @param string $name 插件名称
     * @return string
     * @throws AddonException
     * @throws Exception
     */
    public static function download($name)
    {
        $tmpFile = RUNTIME_PATH . "addons" . DS . $name . ".zip";
        $options = [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'X-REQUESTED-WITH: XMLHttpRequest'
            ]
        ];
        $ret = Http::sendRequest(self::$remote_url, ['name' => $name], 'GET', $options);
        if ($ret['ret'])
        {
            if (substr($ret['msg'], 0, 1) == '{')
            {
                $json = (array) json_decode($ret['msg'], true);
                //下载返回错误，抛出异常
                throw new AddonException($json['msg'], $json['code'], $json['data']);
            }
            if ($write = fopen($tmpFile, 'w'))
            {
                fwrite($write, $ret['msg']);
                fclose($write);
                return $tmpFile;
            }
            throw new Exception("没有权限写入临时文件");
        }
        throw new Exception("无法下载远程文件");
    }

    /**
     * 解压插件
     * 
     * @param string $name 插件名称
     * @return string
     * @throws Exception
     */
    public static function unzip($name)
    {
        $file = RUNTIME_PATH . 'addons' . DS . $name . '.zip';
        $dir = ADDON_PATH . $name . DS;
        if (class_exists('ZipArchive'))
        {
            $zip = new ZipArchive;
            if ($zip->open($file) !== TRUE)
            {
                throw new Exception('Unable to open the zip file');
            }
            if (!$zip->extractTo($dir))
            {
                $zip->close();
                throw new Exception('Unable to extract the file');
            }
            $zip->close();
            return $dir;
        }
        throw new Exception("无法执行解压操作，请确保ZipArchive安装正确");
    }

    /**
     * 检测插件是否完整
     * 
     * @param string $name 插件名称
     * @return boolean
     * @throws Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name))
        {
            throw new Exception('Addon not exists');
        }
        $addonClass = get_addon_class($name);
        if (!$addonClass)
        {
            throw new Exception("插件主启动程序不存在");
        }
        $addon = new $addonClass();
        if (!$addon->checkInfo())
        {
            throw new Exception("配置文件不完整");
        }
        return true;
    }

    /**
     * 是否有冲突
     * 
     * @param string $name 插件名称
     * @return boolean
     * @throws AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list)
        {
            //发现冲突文件，抛出异常
            throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /**
     * 导入SQL
     * @param string $name
     */
    public static function importsql($name)
    {
        $sqlFile = ADDON_PATH . $name . DS . 'install.sql';
        if (is_file($sqlFile))
        {
            try
            {
                $sql = str_replace('__PREFIX__', config('database.prefix'), file_get_contents($sqlFile));
                // 导入SQL
                Db::getPdo()->exec($sql);
            }
            catch (\think\exception\PDOException $e)
            {
                throw new Exception($e->getMessage());
            }
        }
        return true;
    }

    /**
     * 刷新插件缓存文件
     * 
     * @return boolean
     * @throws Exception
     */
    public static function refresh()
    {
        //刷新addons.js
        $addons = get_addon_list();
        $bootstrapArr = [];
        foreach ($addons as $name => $addon)
        {
            $bootstrapFile = ADDON_PATH . $name . DS . 'bootstrap.js';
            if ($addon['state'] && is_file($bootstrapFile))
            {
                $bootstrapArr[] = file_get_contents($bootstrapFile);
            }
        }
        $addonsFile = ROOT_PATH . str_replace("/", DS, "public/assets/js/addons.js");
        if ($handle = fopen($addonsFile, 'w'))
        {
            $tpl = <<<EOD
define(['backend'], function (Backend) {
    {__JS__}
});
EOD;
            fwrite($handle, str_replace("{__JS__}", implode("\r\n", $bootstrapArr), $tpl));
            fclose($handle);
        }
        else
        {
            throw new Exception("addons.js文件没有写入权限");
        }

        $file = APP_PATH . 'extra' . DS . '/addons.php';

        $config = get_addon_autoload_config(true);
        if ($config['autoload'])
            return;

        if (!is_really_writable($file))
        {
            throw new Exception("addons.php文件没有写入权限");
        }

        if ($handle = fopen($file, 'w'))
        {
            fwrite($handle, "<?php\r\n\r\n" . "return " . var_export($config, TRUE) . ";");
            fclose($handle);
        }
        else
        {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }

    /**
     * 安装插件
     * 
     * @param string $name 插件名称
     * @param boolean $force 是否覆盖
     * @return boolean
     * @throws Exception
     * @throws AddonException
     */
    public static function install($name, $force = false)
    {
        if (!$name || (is_dir(ADDON_PATH . $name) && !$force))
        {
            throw new Exception('Addon already exists');
        }

        // 远程下载插件
        $tmpFile = Service::download($name);

        // 解压插件
        $addonDir = Service::unzip($name);

        // 移除临时文件
        @unlink($tmpFile);

        try
        {
            // 检查插件是否完整
            Service::check($name);

            if (!$force)
            {
                Service::noconflict($name);
            }
        }
        catch (AddonException $e)
        {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        }
        catch (Exception $e)
        {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        }

        // 复制文件
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($sourceAssetsDir))
        {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        foreach (self::$check_dirs as $k => $dir)
        {
            if (is_dir($addonDir . $dir))
            {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }

        // 执行安装脚本
        try
        {
            $class = get_addon_class($name);
            if (class_exists($class))
            {
                $addon = new $class();
                $addon->install();
            }
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage());
        }

        // 导入
        Service::importsql($name);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 卸载插件
     * 
     * @param string $name
     * @param boolean $force 是否强制卸载
     * @return boolean
     * @throws Exception
     */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name))
        {
            throw new Exception('Addon not exists');
        }

        if (!$force)
        {
            Service::noconflict($name);
        }

        // 移除插件基础资源目录
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($destAssetsDir))
        {
            rmdirs($destAssetsDir);
        }

        // 移除插件全局资源文件
        if ($force)
        {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v)
            {
                @unlink(ROOT_PATH . $v);
            }
        }

        // 执行卸载脚本
        try
        {
            $class = get_addon_class($name);
            if (class_exists($class))
            {
                $addon = new $class();
                $addon->uninstall();
            }
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage());
        }

        // 移除插件目录
        rmdirs(ADDON_PATH . $name);

        // 刷新
        Service::refresh();
        return true;
    }

    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name))
        {
            throw new Exception('Addon not exists');
        }

        try
        {
            if (!$force)
            {
                Service::noconflict($name);
            }
        }
        catch (AddonException $e)
        {
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage());
        }

        $addonDir = ADDON_PATH . $name . DS;

        // 复制文件
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($sourceAssetsDir))
        {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        foreach (self::$check_dirs as $k => $dir)
        {
            if (is_dir($addonDir . $dir))
            {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }

        $info = get_addon_info($name);
        $info['state'] = 1;
        unset($info['url']);

        set_addon_info($name, $info);

        // 刷新
        Service::refresh();
        return true;
    }

    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name))
        {
            throw new Exception('Addon not exists');
        }

        if (!$force)
        {
            Service::noconflict($name);
        }

        // 移除插件基础资源目录
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($destAssetsDir))
        {
            rmdirs($destAssetsDir);
        }

        // 移除插件全局资源文件
        if ($force)
        {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v)
            {
                @unlink(ROOT_PATH . $v);
            }
        }

        $info = get_addon_info($name);
        $info['state'] = 0;

        set_addon_info($name, $info);
        unset($info['url']);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 获取插件在全局的文件
     * 
     * @param string $name
     * @return array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = ADDON_PATH . $name . DS;
        // 扫描插件目录是否有覆盖的文件
        foreach (self::$check_dirs as $k => $dir)
        {
            $checkDir = ROOT_PATH . DS . $dir . DS;
            if (!is_dir($checkDir))
                continue;
            //检测到存在插件外目录
            if (is_dir($addonDir . $dir))
            {
                //匹配出所有的文件
                $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($addonDir . $dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $fileinfo)
                {
                    if ($fileinfo->isFile())
                    {
                        $filePath = $fileinfo->getPathName();
                        $path = str_replace($addonDir, '', $filePath);
                        if ($onlyconflict)
                        {
                            if (is_file(ROOT_PATH . $path))
                            {
                                $list[] = $path;
                            }
                        }
                        else
                        {
                            $list[] = $path;
                        }
                    }
                }
            }
        }
        return $list;
    }

    /**
     * 获取插件源资源文件夹
     * @param string $name
     * @return string
     */
    protected static function getSourceAssetsDir($name)
    {
        return ADDON_PATH . $name . DS . 'assets' . DS;
    }

    /**
     * 获取插件目标资源文件夹
     * @param string $name
     * @return string
     */
    protected static function getDestAssetsDir($name)
    {
        return ROOT_PATH . str_replace("/", DS, "public/assets/addons/{$name}/");
    }

}
