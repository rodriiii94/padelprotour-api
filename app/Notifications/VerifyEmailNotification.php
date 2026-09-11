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
        $url = 'padelontourapp://verify-email?token='.$this->token;

        return (new MailMessage)
            ->subject('Verifica tu email en PadelProTour')
            ->line('Confirma tu email para activar tu cuenta de PadelProTour.')
            ->action('Verificar email', $url)
            ->line('Si no has creado esta cuenta, puedes ignorar este mensaje.');
    }
}
