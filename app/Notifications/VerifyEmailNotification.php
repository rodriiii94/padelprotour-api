<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $webUrl = rtrim(config('app.frontend_url'), '/').'/verify-email?token='.$this->token;
        $appUrl = 'padelontourapp://verify-email?token='.$this->token;

        return (new MailMessage)
            ->subject('Verifica tu email en PadelProTour')
            ->line('Confirma tu email para activar tu cuenta de PadelProTour.')
            ->action('Verificar email', $webUrl)
            ->line("¿Te has registrado desde la app en el móvil? [Ábrela con este enlace]({$appUrl}).")
            ->line('Si no has creado esta cuenta, puedes ignorar este mensaje.');
    }
}
