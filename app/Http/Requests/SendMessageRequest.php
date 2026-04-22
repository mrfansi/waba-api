<?php

namespace App\Http\Requests;

use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'string'],
            'type' => ['required', 'in:text,media,template,interactive,location,contact'],
            'mode' => ['nullable', 'in:sync,queue'],

            'text' => ['required_if:type,text', 'array'],
            'text.body' => ['required_if:type,text', 'string'],
            'text.preview_url' => ['sometimes', 'boolean'],

            'media' => ['required_if:type,media', 'array'],
            'media.kind' => ['required_if:type,media', 'in:image,video,audio,document,sticker'],
            'media.url' => ['required_if:type,media', 'url'],
            'media.caption' => ['nullable', 'string'],
            'media.filename' => ['nullable', 'string'],

            'template' => ['required_if:type,template', 'array'],
            'template.name' => ['required_if:type,template', 'string'],
            'template.language' => ['required_if:type,template', 'string'],
            'template.components' => ['sometimes', 'array'],

            'interactive' => ['required_if:type,interactive', 'array'],
            'interactive.kind' => ['required_if:type,interactive', 'in:button,list'],
            'interactive.body' => ['required_if:type,interactive', 'string'],
            'interactive.action' => ['required_if:type,interactive', 'array'],
            'interactive.header' => ['nullable', 'string'],
            'interactive.footer' => ['nullable', 'string'],

            'location' => ['required_if:type,location', 'array'],
            'location.latitude' => ['required_if:type,location', 'numeric'],
            'location.longitude' => ['required_if:type,location', 'numeric'],
            'location.name' => ['nullable', 'string'],
            'location.address' => ['nullable', 'string'],

            'contacts' => ['required_if:type,contact', 'array'],
        ];
    }

    public function toDto(): OutboundMessage
    {
        $type = $this->input('type');
        $idempotency = $this->header('Idempotency-Key');

        $payload = match ($type) {
            'text' => new TextPayload($this->input('text.body'), (bool) $this->input('text.preview_url', false)),
            'media' => new MediaPayload(
                kind: $this->input('media.kind'),
                url: $this->input('media.url'),
                caption: $this->input('media.caption'),
                filename: $this->input('media.filename'),
            ),
            'template' => new TemplatePayload(
                name: $this->input('template.name'),
                language: $this->input('template.language'),
                components: $this->input('template.components', []),
            ),
            'interactive' => new InteractivePayload(
                kind: $this->input('interactive.kind'),
                body: $this->input('interactive.body'),
                action: $this->input('interactive.action'),
                header: $this->input('interactive.header'),
                footer: $this->input('interactive.footer'),
            ),
            'location' => new LocationPayload(
                latitude: (float) $this->input('location.latitude'),
                longitude: (float) $this->input('location.longitude'),
                name: $this->input('location.name'),
                address: $this->input('location.address'),
            ),
            'contact' => new ContactPayload($this->input('contacts', [])),
        };

        return new OutboundMessage(
            to: $this->input('to'),
            type: $type,
            payload: $payload,
            idempotencyKey: is_string($idempotency) ? $idempotency : null,
            clientReference: $this->input('client_reference'),
        );
    }

    public function mode(): string
    {
        return $this->header('X-Send-Mode')
            ?: $this->input('mode')
            ?: (string) config('waba.outbound.default_mode', 'queue');
    }
}
