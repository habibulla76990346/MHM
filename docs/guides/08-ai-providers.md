# 08 — AI provider configuration

Aziv AI answers nothing until at least one provider has a key and one model is enabled. Nothing in
the code names a provider: everything below is data you enter.

## The four steps, in order

1. **Admin → AI Providers → Add.** Pick a preset (OpenAI, Anthropic, Gemini, DeepSeek, Mistral,
   Groq, OpenRouter, Hugging Face) or type a name and an address for one that is not listed. A
   preset fills in the address and how the key is sent; every value it fills in stays editable.
2. **Credentials.** Paste the key. It is encrypted with `APP_KEY` the moment you save, it is never
   shown again, and nothing outside the request that calls the provider can read it back.
3. **Refresh the catalog.** The provider is asked which models it offers, and the answer is stored
   as rows you can see. Nothing is enabled by this — it only populates the list.
4. **Enable the models you want** and, if you are charging for them, set their prices on
   Admin → AI Models → *(a model)* → Prices.

## Where the keys come from

| Provider | Where |
|---|---|
| OpenAI | platform.openai.com → API keys |
| Anthropic | console.anthropic.com → API keys |
| Google Gemini | aistudio.google.com → Get API key |
| Others | Each provider's own console; the preset names the address it will call |

A key is an account with a bill attached. Create a **separate key for Aziv AI**, so revoking it
later does not break anything else you own.

## Prices, and why an unpriced model shows zero

A model with no price recorded costs a visible **zero** rather than a guess. That is deliberate:
a zero can be found and corrected, an invented number cannot. Set the provider cost and the credit
price per unit, with an effective-from date, and past calls keep the price that applied when they
happened. Editing a price never rewrites history.

Voice models are priced **per second** and image models **per image**. Getting the unit wrong is
the one mistake here that quietly under- or over-charges everybody.

## Budgets

Admin → AI Providers → *(a provider)* → Budgets sets a spending ceiling per period.

- **Warn** keeps serving and tells you.
- **Block** stops spending on that provider until the next period. Requests move to another
  provider that can do the job, or fail cleanly if none can.

Set one before you hand the platform to real customers. A loop in somebody's script is a bill.

## Testing without spending real money

Admin → AI Providers → *(a provider)* → **Test connection** makes one minimal call and reports
whether the credential works. It never prints the key, and a provider's raw error text never
reaches the screen — several APIs echo the failing request back, and that request carried the key.

## Which models can do what

The application never asks for a model by name. It asks for a **capability** — text, vision,
images, speech, embeddings — and the router picks from the enabled models that declare it. So:

- **Chat with images** needs at least one enabled model with vision.
- **Knowledge bases** need at least one model with embeddings, or indexing never finishes.
- **Image generation** needs an image model, and **voice** needs speech-to-text, text-to-speech, or
  both. Admin → Media → Images and voice says at the top whether one exists.

## Outbound network access

Every one of these is an outbound HTTPS call from your server. If your host blocks outbound
connections, nothing here works and the failure looks like a broken key. See
[17a — Hosting requirements](17a-hosting-requirements.md) for the page to send your provider.
