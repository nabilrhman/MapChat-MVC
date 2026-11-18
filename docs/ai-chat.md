# AI chat

How MapChat and the characters on the map chat back, and what they read.
Back to the [README](../README.md).

Two kinds of AI share the room, and Groq's chat API (`openai/gpt-oss-120b` by
default) writes what both say. Without `GROQ_API_KEY`, MapChat only greets and
the characters only follow their script.

**MapChat**, the room's own account. It has no pin on the map, and the
people list places it on Null Island:

- Says hello when someone walks into a quiet room, once a day per person and
  at most once every 10 minutes in the room.
- Answers someone nobody else answered, giving others 45 seconds first.
- Keeps a conversation going, and steps back when people talk to each other.
  An @MapChat always gets an answer.
- Answers private messages.

**The characters**, the nine people on the map, are AI too. Through
the day they play out stories from `database/demo-chat.php`, and:

- @mention one, or message them privately, and they answer in character,
  remembering what's happened in their stories so far.
- While a guest talks with one, the story waits for them.
- Asked whether they're real, they say they're AI.

Both keep replies short, tag who they're answering, and stop asking
questions once someone's done chatting. A room reply reads only the room's
recent messages, and a private reply only that chat. That text is sent to
Groq, which [says](https://console.groq.com/docs/your-data) it doesn't train
on what's sent to its API. The code is in `App/Models/Bot.php`.

The same key turns voice messages into text with Groq's
`whisper-large-v3-turbo` (`App/Bot/Groq.php`, `public/js/messages/voice.js`). Its free plan
allows 2,000 recordings a day and 20 a minute for the whole site; past that,
the page has the browser listen instead. MapChat doesn't keep the recordings.
