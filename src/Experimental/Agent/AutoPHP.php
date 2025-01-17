<?php

namespace LLPhant\Experimental\Agent;

use LLPhant\Chat\Enums\OpenAIChatModel;
use LLPhant\Chat\FunctionInfo\FunctionBuilder;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\FunctionRunner;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;
use LLPhant\Utils\CLIOutputUtils;

use function Termwind\terminal;

class AutoPHP
{
    public OpenAIChat $openAIChat;

    public TaskManager $taskManager;

    public CreationTaskAgent $creationTaskAgent;

    public PrioritizationTaskAgent $prioritizationTaskAgent;

    public string $defaultModelName;

    /**
     * @param  FunctionInfo[]  $tools
     */
    public function __construct(
        public string $objective,
        /* @var FunctionInfo[] */
        public array $tools,
        public bool $verbose = false,
    ) {
        $this->taskManager = new TaskManager();
        $this->openAIChat = new OpenAIChat();
        $this->creationTaskAgent = new CreationTaskAgent($this->taskManager, new OpenAIChat(), $verbose);
        $this->prioritizationTaskAgent = new PrioritizationTaskAgent($this->taskManager, new OpenAIChat(), $verbose);
        $this->defaultModelName = OpenAIChatModel::Gpt4Turbo->getModelName();
    }

    public function run(int $maxIteration = 100): string
    {
        terminal()->clear();
        CLIOutputUtils::renderTitle('🐘 AutoPHP 🐘', '🎯 Objective: '.$this->objective, $this->verbose);
        $this->creationTaskAgent->createTasks($this->objective, $this->tools);
        CLIOutputUtils::printTasks($this->verbose, $this->taskManager->tasks);
        $currentTask = $this->prioritizationTaskAgent->prioritizeTask($this->objective);
        $iteration = 1;
        while ($currentTask instanceof Task && $maxIteration >= $iteration) {
            CLIOutputUtils::printTasks($this->verbose, $this->taskManager->tasks, $currentTask);

            // TODO: add a mechanism to retrieve short-term / long-term memory
            $previousCompletedTask = $this->taskManager->getAchievedTasksNameAndResult();
            $context = "Previous tasks status: {$previousCompletedTask}";

            // TODO: add a mechanism to get the best tool for a given Task
            $executionAgent = new ExecutionTaskAgent($this->tools, new OpenAIChat(), $this->verbose);
            $currentTask->result = $executionAgent->run($this->objective, $currentTask, $context);

            CLIOutputUtils::printTasks($this->verbose, $this->taskManager->tasks);
            if ($finalResult = $this->getObjectiveResult()) {
                CLIOutputUtils::renderTitle('🏆️ Success! 🏆️', 'Result: '.$finalResult, true);

                return $finalResult;
            }

            if (count($this->taskManager->getUnachievedTasks()) <= 0) {
                $this->creationTaskAgent->createTasks($this->objective, $this->tools);
            }

            $currentTask = $this->prioritizationTaskAgent->prioritizeTask($this->objective);
            $iteration++;
        }

        return "failed to achieve objective in {$iteration} iterations";
    }

    private function getObjectiveResult(): ?string
    {
        $config = new OpenAIConfig();
        $config->model = $this->defaultModelName;
        $model = new OpenAIChat($config);
        $autoPHPInternalTool = new AutoPHPInternalTool();
        $enoughDataToFinishFunction = FunctionBuilder::buildFunctionInfo($autoPHPInternalTool, 'objectiveStatus');
        $model->setFunctions([$enoughDataToFinishFunction]);
        $model->requiredFunction = $enoughDataToFinishFunction;

        $achievedTasks = $this->taskManager->getAchievedTasksNameAndResult();
        $unachievedTasks = $this->taskManager->getUnachievedTasksNameAndResult();

        $prompt = "Consider the ultimate objective of your team: {$this->objective}."
            .'Based on the result from previous tasks, you need to determine if the objective has been achieved.'
            ."The previous tasks are: {$achievedTasks}."
            ."Remaining tasks: {$unachievedTasks}."
            ."If the objective has been completed, give the exact answer to the objective {$this->objective}.";

        $stringOrFunctionInfo = $model->generateTextOrReturnFunctionCalled($prompt);
        if (! $stringOrFunctionInfo instanceof FunctionInfo) {
            // Shouldn't be null as OPENAI should call the function
            return null;
        }

        $objectiveData = FunctionRunner::run($stringOrFunctionInfo);
        if (! is_array($objectiveData)) {
            // The wrong function has probably been called, shouldn't happen
            return null;
        }

        if ($objectiveData['objectiveCompleted']) {
            return $objectiveData['answer'];
        }

        return null;
    }
}
