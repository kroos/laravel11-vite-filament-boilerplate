<?php
namespace App\Providers\Filament\Auth;

use Filament\Pages\Auth\EditProfile as BaseEditProfile;

use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Support\Enums\Alignment;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\Validation\Rules\Password;
use Throwable;

use function Filament\Support\is_app_url;

class EditProfile extends BaseEditProfile
{
	public function getUser(): Authenticatable & Model
	{
		$user = Filament::auth()->user();

		if (! $user instanceof Model) {
			throw new Exception('The authenticated user object must be an Eloquent model to allow the profile page to update it.');
		}

		return $user;
	}

	protected function fillForm(): void
	{
		$data = $this->getUser()->attributesToArray();

		$this->callHook('beforeFill');

		$data = $this->mutateFormDataBeforeFill($data);

		$this->form->fill($data);

		$this->callHook('afterFill');
	}

	public static function registerRoutes(Panel $panel): void
	{
		if (filled(static::getCluster())) {
			Route::name(static::prependClusterRouteBaseName(''))
			->prefix(static::prependClusterSlug(''))
			->group(fn () => static::routes($panel));

			return;
		}

		static::routes($panel);
	}

	public static function getRouteName(?string $panel = null): string
	{
		$panel = $panel ? Filament::getPanel($panel) : Filament::getCurrentPanel();

		return $panel->generateRouteName('auth.' . static::getRelativeRouteName());
	}

	protected function mutateFormDataBeforeFill(array $data): array
	{
		return $data;
	}

	protected function mutateFormDataBeforeSave(array $data): array
	{
		return $data;
	}

	public function save(): void
	{
		try {
			$this->beginDatabaseTransaction();
			$this->callHook('beforeValidate');
			$data = $this->form->getState();
			$this->callHook('afterValidate');
			$data = $this->mutateFormDataBeforeSave($data);
			$this->callHook('beforeSave');
			$this->handleRecordUpdate($this->getUser(), $data);
			$this->callHook('afterSave');
			$this->commitDatabaseTransaction();
		} catch (Halt $exception) {
			$exception->shouldRollbackDatabaseTransaction() ?
			$this->rollBackDatabaseTransaction() :
			$this->commitDatabaseTransaction();

			return;
		} catch (Throwable $exception) {
			$this->rollBackDatabaseTransaction();

			throw $exception;
		}

		if (request()->hasSession() && array_key_exists('password', $data)) {
			request()->session()->put([
				'password_hash_' . Filament::getAuthGuard() => $data['password'],
			]);
		}

		$this->data['password'] = null;
		$this->data['passwordConfirmation'] = null;

		$this->getSavedNotification()?->send();

		if ($redirectUrl = $this->getRedirectUrl()) {
			$this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode() && is_app_url($redirectUrl));
		}
	}

	protected function handleRecordUpdate(Model $record, array $data): Model
	{
		// $record->update($data);
    $record->update([
        'password' => $data['password'] ?? $record->password, // Only update if password is provided
    ]);
    // Ensure that `belongstouser` relationship exists
    if ($record->belongstouser) {
        $record->belongstouser->update([
            'name' => $data['name'] ?? $record->belongstouser->name,
            'email' => $data['email'] ?? $record->belongstouser->email,
        ]);
    }
		return $record;
	}

	protected function getSavedNotification(): ?Notification
	{
		$title = $this->getSavedNotificationTitle();

		if (blank($title)) {
			return null;
		}

		return Notification::make()
		->success()
		->title($this->getSavedNotificationTitle());
	}

	protected function getSavedNotificationTitle(): ?string
	{
		return __('filament-panels::pages/auth/edit-profile.notifications.saved.title');
	}

	protected function getRedirectUrl(): ?string
	{
		return null;
	}

	protected function getNameFormComponent(): Component
	{
		return TextInput::make('name')
		->label(__('filament-panels::pages/auth/edit-profile.form.name.label'))
		->required()
		->maxLength(255)
		->autofocus();
	}

	protected function getUsernameFormComponent(): Component
	{
		return TextInput::make('username')
		->label('Username')
		->required()
		->maxLength(255)
		->autofocus();
	}

	protected function getEmailFormComponent(): Component
	{
		return TextInput::make('email')
		->label(__('filament-panels::pages/auth/edit-profile.form.email.label'))
		->email()
		->required()
		->maxLength(255)
		->unique(ignoreRecord: true);
	}

	protected function getPasswordFormComponent(): Component
	{
		return TextInput::make('password')
		->label(__('filament-panels::pages/auth/edit-profile.form.password.label'))
		->password()
		->revealable(filament()->arePasswordsRevealable())
		->rule(Password::default())
		->autocomplete('new-password')
		->dehydrated(fn ($state): bool => filled($state))
		->dehydrateStateUsing(fn ($state): string => Hash::make($state))
		->live(debounce: 500)
		->same('passwordConfirmation');
	}

	protected function getPasswordConfirmationFormComponent(): Component
	{
		return TextInput::make('passwordConfirmation')
		->label(__('filament-panels::pages/auth/edit-profile.form.password_confirmation.label'))
		->password()
		->revealable(filament()->arePasswordsRevealable())
		->required()
		->visible(fn (Get $get): bool => filled($get('password')))
		->dehydrated(false);
	}

	public function form(Form $form): Form
	{
		return $form;
	}

	/**
	* @return array<int | string, string | Form>
	*/
	protected function getForms(): array
	{
		return [
			'form' => $this->form(
				$this->makeForm()
				->schema([
					$this->getNameFormComponent(),
					$this->getEmailFormComponent(),
					$this->getUsernameFormComponent(),
					$this->getPasswordFormComponent(),
					$this->getPasswordConfirmationFormComponent(),
				])
				->operation('edit')
				->model($this->getUser())
				->statePath('data')
				->inlineLabel(! static::isSimple()),
			),
		];
	}

		/**
		* @return array<Action | ActionGroup>
		*/
		protected function getFormActions(): array
		{
			return [
				$this->getSaveFormAction(),
				$this->getCancelFormAction(),
			];
		}

		protected function getCancelFormAction(): Action
		{
			return $this->backAction();
		}

		protected function getSaveFormAction(): Action
		{
			return Action::make('save')
			->label(__('filament-panels::pages/auth/edit-profile.form.actions.save.label'))
			->submit('save')
			->keyBindings(['mod+s']);
		}

		protected function hasFullWidthFormActions(): bool
		{
			return false;
		}

		public function getFormActionsAlignment(): string | Alignment
		{
			return Alignment::Start;
		}

		public function getTitle(): string | Htmlable
		{
			return static::getLabel();
		}

		public static function getSlug(): string
		{
			return static::$slug ?? 'profile';
		}

		public function hasLogo(): bool
		{
			return false;
		}

		/**
		* @deprecated Use `getCancelFormAction()` instead.
		*/
		public function backAction(): Action
		{
			return Action::make('back')
			->label(__('filament-panels::pages/auth/edit-profile.actions.cancel.label'))
			->alpineClickHandler('document.referrer ? window.history.back() : (window.location.href = ' . Js::from(filament()->getUrl()) . ')')
			->color('gray');
		}

		protected function getLayoutData(): array
		{
			return [
				'hasTopbar' => $this->hasTopbar(),
				'maxWidth' => $this->getMaxWidth(),
			];
		}
	}
