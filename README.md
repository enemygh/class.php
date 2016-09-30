```php
// 尝试加载未定义的类
spl_autoload_register(function($class) {
	include $class . '.class.php';
});
```
