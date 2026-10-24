# Assistant runtime research brief

Implement Slack Assistant lifecycle parity in idiomatic PHP while retaining the existing delivery, authorization and middleware pipeline. Compare Slack/Bolt JS, Laravel AI, and Vercel Eve for useful patterns and a viable optional bridge.

Questions:
1. Which lifecycle events and assistant-thread message filters must match Bolt JS?
2. Where should context live, and how do updates and restarts preserve it without leaking across installations?
3. What small thread interface can supply replies, status, titles and suggested prompts?
4. Can Laravel AI interoperate without requiring Laravel in the core package?
5. Which Eve patterns improve Slack handling without introducing an agent framework?

Sources: pinned Bolt JS under work/reference-sources; current official Slack docs; laravel/ai official docs/source; Vercel Eve official source.

Reports should cover contracts, workflows, strengths/limitations, exact sources and confidence. Prioritize implementation-relevant evidence; record unknowns explicitly. Synthesize decisions in assistant-runtime-research.md.
