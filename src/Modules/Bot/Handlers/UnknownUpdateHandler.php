<?php
namespace Modules\Bot\Handlers;

class UnknownUpdateHandler extends BaseHandler {
    public function handle($update) {
        // Acknowledge callback to dismiss loading icon on user's side, then do nothing
        if ($update->isCallback()) {
            $this->baleClient->answerCallbackQuery($update->getCallbackId(), null, false);
        }
        $this->logger->info('UnknownUpdateHandler: unrecognized update type');
    }
}