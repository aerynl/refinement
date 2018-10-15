<?php namespace Aerynl\Refinement;

use Illuminate\Support\ServiceProvider;

class RefinementServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$configPath = __DIR__ . '/../config/config.php';
		$this->publishes([$configPath => config_path('refinement.php')], 'config');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->mergeConfigFrom( __DIR__.'/../config/config.php', 'refinement');

		$this->app['refinement'] = $this->app->singleton('refinement', function(){
			return new Refinement();
		});
	}

}
